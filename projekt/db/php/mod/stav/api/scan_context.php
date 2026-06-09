<?php
$db = DB::getInstance();

$old    = $db->run('synapse_uzly', 'select');
$oldIds = array_column($old, 'id');

$added  = [];
$removed = [];

$upsertNode = function (array $node, array $props, $fileToRead = null) use ($db): void {
    if (empty($node['popis'])) {
        $node['popis'] = "Fragment: {$node['typ']} "._getDefaultPopis($node['typ'], $node['id'], $node['nazev'] ?? '');
    }
    
    // 1. Načtení stávajícího stavu z DB
    $exist = $db->run('synapse_uzly', 'select', ['where' => ['id' => $node['id']]]);
    $staryZaznam = !empty($exist) ? $exist[0] : null;

    if ($fileToRead && file_exists($fileToRead)) {
        if (empty($staryZaznam) || empty($staryZaznam['kod'])) {
            $node['kod'] = file_get_contents($fileToRead);
        } else {
            // Pokud kód v DB už je, zachováme ho pro porovnání
            $node['kod'] = $staryZaznam['kod'];
        }
    }

    // 2. KONTROLA ZMĚN: Pokud starý záznam existuje, porovnáme klíčová pole
    $jeZmena = true;
    if ($staryZaznam) {
        $jeZmena = false;
        foreach (['typ', 'nazev', 'skupina', 'popis', 'kod'] as $klic) {
            // Pokud se liší jakákoliv reálná hodnota, teprve tehdy povolíme zápis verze
            if (($node[$klic] ?? '') !== ($staryZaznam[$klic] ?? '')) {
                $jeZmena = true;
                break;
            }
        }
    }

    // Zápis do uzlů provedeme POUZE v případě, že se data reálně změnila
    if ($jeZmena) {
        $db->run('synapse_uzly', 'upsert', $node);
    }

    // 3. SPRÁVA VLASTNOSTÍ: Porovnáme stávající vlastnosti, abychom je neustále nepřepisovali
    $stareVlastnosti = $db->run('synapse_vlastnosti', 'select', ['where' => ['uzel_id' => $node['id']]]);
    
    // Sestavíme kontrolní otisky pro rychlé porovnání polí
    $stareKlice = array_map(fn($v) => ($v['kategorie'] ?? '').'='.($v['hodnota'] ?? ''), $stareVlastnosti);
    $noveKlice = array_map(fn($p) => ($p['kategorie'] ?? '').'='.($p['hodnota'] ?? ''), $props);
    
    sort($stareKlice);
    sort($noveKlice);

    // Pokud se seznam vlastností liší, staré smažeme a vložíme nové
    if ($stareKlice !== $noveKlice) {
        $db->run('synapse_vlastnosti', 'delete', ['where' => ['uzel_id' => $node['id']]]);
        foreach ($props as $p) {
            $db->run('synapse_vlastnosti', 'insert', array_merge(['uzel_id' => $node['id']], $p));
        }
    }
};


// --- A. SKEN A ULOŽENÍ FRAGMENTŮ ---
$mod = Registry::getModules();
foreach ($mod as $slug => $mod) {
    $upsertNode(['id' => "mod_{$slug}", 'typ' => 'modul', 'nazev' => $mod['name'], 'skupina' => 'fyzicka', 'popis' => $mod['description'] ?? ''], [
            ['kategorie' => 'vrstva',  'hodnota' => 'backend_modul'],
            ['kategorie' => 'ikona',   'hodnota' => $mod['icon'] ?? '📦'],
            ['kategorie' => 'poradi',  'hodnota' => (string)($mod['order'] ?? 99)],], PC_ROOT . "/php/mod/{$slug}/module.php");
    foreach (glob(PC_ROOT . "/php/mod/{$slug}/api/*.php") ?: [] as $file) {
        $apiName = basename($file, '.php');
        if ($apiName === 'render') continue; // Render se řeší v UI
		
        $operace = 'čtení';
        if (preg_match('/create|add|insert|save|submit/', $apiName)) $operace = 'zápis';
        if (preg_match('/update|edit|patch/',              $apiName)) $operace = 'mutace';
        if (preg_match('/delete|remove|destroy/',          $apiName)) $operace = 'mazání';
        if (preg_match('/scan|map|analyze|test/',          $apiName)) $operace = 'analýza';
        if (preg_match('/deploy|sandbox|run/',             $apiName)) $operace = 'nasazení';
		
        $upsertNode(['id' => "api_{$slug}_{$apiName}", 'typ' => 'api', 'nazev' => "API: {$slug}/{$apiName}", 'skupina' => 'fyzicka'], [
                ['kategorie' => 'vrstva',    'hodnota' => 'php_api'],
                ['kategorie' => 'operace',   'hodnota' => $operace],
                ['kategorie' => 'modul_ref', 'hodnota' => $slug],
		], $file);
    }

    if (!empty($mod['db']['table'])) {
        $tblId = "db_" . $mod['db']['table'];
        $upsertNode(
            ['id' => $tblId, 'typ' => 'db_tabulka', 'nazev' => "DB: " . $mod['db']['table'], 'skupina' => 'fyzicka'],
            [
                ['kategorie' => 'vrstva',    'hodnota' => 'databaze'],
                ['kategorie' => 'modul_ref', 'hodnota' => $slug],
            ]
        );
    }
}

// Sken JS z ui.js
$uiJs = @file_get_contents(PC_ROOT . '/js/ui.js');
if ($uiJs && preg_match_all('/\bPC\.([a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_]+)?)\s*[=:(]/', $uiJs, $m)) {
    foreach (array_unique($m[1]) as $jsNode) {
        $druh   = strpos($jsNode, '.') !== false ? 'metoda' : 'modul_pc';
        $upsertNode(['id' => 'js_PC_' . str_replace('.', '_', $jsNode), 'typ' => 'js', 'nazev' => "PC.{$jsNode}", 'skupina' => 'fyzicka'], [
                ['kategorie' => 'vrstva',  'hodnota' => 'javascript'],
                ['kategorie' => 'druh',    'hodnota' => $druh],
                ['kategorie' => 'operace', 'hodnota' => 'ui_akce'],
				]);
    }
}

$css = @file_get_contents(PC_ROOT . '/css/ui.css');
if ($css && preg_match_all('/--([a-zA-Z0-9_-]+)\s*:\s*([^;]+);/', $css, $m)) {
    foreach (array_unique($m[1]) as $i => $varName) {
        $varId = 'css_' . str_replace('-', '_', $varName);
        $upsertNode(
            ['id' => $varId, 'typ' => 'css_var', 'nazev' => "--{$varName}", 'skupina' => 'abstraktni'],
            [
                ['kategorie' => 'vrstva',      'hodnota' => 'css_design_token'],
                ['kategorie' => 'vychozi_val', 'hodnota' => trim($m[2][$i])],
            ]
        );
    }
}
/* 
$abstrakt = [
    ['id'=>'abs_cteni',    'nazev'=>'Extrakce poznatků',  'kat'=>'operace',       'popis'=>'Čistá operace čtení stavu systému bez vedlejších efektů.'],
    ['id'=>'abs_zapis',    'nazev'=>'Zápis do buněk',     'kat'=>'operace',       'popis'=>'Trvalá změna dat v JSON tabulce.'],
    ['id'=>'abs_mutace',   'nazev'=>'Mutace vlastností',  'kat'=>'operace',       'popis'=>'Evoluční transformace existujících struktur.'],
    ['id'=>'abs_nasazeni', 'nazev'=>'Nasazení do provozu','kat'=>'operace',       'popis'=>'Deploy schváleného řetězce uzlů do aktivní platformy.'],
    ['id'=>'abs_ui_akce',  'nazev'=>'Receptor vstupu',    'kat'=>'ui',            'popis'=>'JS komponenta přijímající vstupy od uživatele.'],
    ['id'=>'abs_backend',  'nazev'=>'Kognitivní jádro',   'kat'=>'architektura',  'popis'=>'PHP logický procesor.'],
    ['id'=>'abs_databaze', 'nazev'=>'Genetická paměť',    'kat'=>'architektura',  'popis'=>'JSON soubory jako decentralizovaná databáze.'],
    ['id'=>'abs_sandbox',  'nazev'=>'Virtuální prostor',  'kat'=>'testování',     'popis'=>'Izolované prostředí pro testování uzlů.'],
    ['id'=>'abs_synapse',  'nazev'=>'Synapse síť',        'kat'=>'architektura',  'popis'=>'Graf všech uzlů platformy.'],
]; */

// --- ABSTRAKTNÍ UZLY: Kategorie vlastností (logické koncepty platformy) ---
$abstrakt = [
    ['id' => 'abs_cteni',      'nazev' => 'Operace: Čtení',     'kat' => 'operace', 'popis' => 'Uzel pro bezpečné čtení dat z DB bez modifikace.'],
    ['id' => 'abs_zapis',      'nazev' => 'Operace: Zápis',     'kat' => 'operace', 'popis' => 'Uzel měnící stav systému (Insert/Update).'],
    ['id' => 'abs_mutace',     'nazev' => 'Operace: Mutace',    'kat' => 'operace', 'popis' => 'Mění existující strukturu nebo data (Patch).'],
    ['id' => 'abs_mazani',     'nazev' => 'Operace: Mazání',    'kat' => 'operace', 'popis' => 'Odstraňuje data/uzly. Vyžaduje opatrnost.'],
    ['id' => 'abs_ui_akce',    'nazev' => 'UI Akce',            'kat' => 'ui',      'popis' => 'Interakce uživatele. Funguje jako spouštěč (Trigger).'],
    ['id' => 'abs_navigace',   'nazev' => 'Navigace',           'kat' => 'ui',      'popis' => 'Přesměrování mezi moduly bez znovunačtení stránky (SPA).'],
    ['id' => 'abs_modalni',    'nazev' => 'Modální okno',       'kat' => 'ui',      'popis' => 'Překryvná vrstva vyžadující pozornost uživatele.'],
    ['id' => 'abs_formulare',  'nazev' => 'Formuláře',          'kat' => 'ui',      'popis' => 'Sběr dat od uživatele před odesláním na Backend.'],
    ['id' => 'abs_notifikace', 'nazev' => 'Notifikace/Toast',   'kat' => 'ui',      'popis' => 'Krátká informační zpětná vazba pro uživatele.'],
    ['id' => 'abs_backend',    'nazev' => 'Backend logika',     'kat' => 'architektura', 'popis' => 'Skript běžící na serveru, validuje a zpracovává data.'],
    ['id' => 'abs_databaze',   'nazev' => 'Databázová vrstva',  'kat' => 'architektura', 'popis' => 'Úložiště perzistentních dat (JSON).'],
    ['id' => 'abs_bezpecnost', 'nazev' => 'Bezpečnost/CSRF',    'kat' => 'architektura', 'popis' => 'Ochranná vrstva proti podvrženým požadavkům.'],
    ['id' => 'abs_logging',    'nazev' => 'Logování/Audit',     'kat' => 'architektura', 'popis' => 'Zaznamenává činnost pro diagnostiku a historii.'],
];

foreach ($abstrakt as $a) {
    $upsertNode(
        ['id' => $a['id'], 'typ' => 'abstraktni', 'nazev' => $a['nazev'], 'skupina' => 'abstraktni', 'popis' => $a['popis']],
        [
            ['kategorie' => 'vrstva',  'hodnota' => 'abstraktni_kategorie'],
            ['kategorie' => 'skupina', 'hodnota' => $a['kat']],
        ]
    );
}

// Sken HTML komponent (Extrakce čistého jména z tagu)
$komponenty = $db->run('builder_komponenty', 'select');
$htmlUzly = [];
foreach ($komponenty as $k) {
    preg_match('/<([a-z0-9]+)[^>]*class=["\']([^"\']+)["\']/i', $k['html'], $mClass);
    preg_match('/<([a-z0-9]+)/i', $k['html'], $mTag);
    
    $tag = $mTag[1] ?? 'div';
    $cls = isset($mClass[2]) ? explode(' ', $mClass[2])[0] : '';
    $cleanName = "<$tag" . ($cls ? ".$cls" : "") . "> " . ($k['nazev'] ?? '');
    
    $nodeId = 'html_' . $k['id'];
    $htmlUzly[$nodeId] = $k['html']; // Uložíme pro analýzu vazeb
    $upsertNode(['id' => $nodeId, 'typ' => 'html_komponenta', 'nazev' => $cleanName, 'skupina' => 'fyzicka', 'kod' => $k['html']], []);
}

$now    = $db->run('synapse_uzly', 'select');
$nowIds = array_column($now, 'id');
foreach (array_diff($nowIds, $oldIds) as $id) $added[]   = $id;
foreach (array_diff($oldIds, $nowIds) as $id) $removed[] = $id;

if (!empty($added) || !empty($removed)) {
    $db->run('synapse_changelog', 'insert', [
        'added'   => $added,
        'removed' => $removed,
        'total'   => count($nowIds),
    ]);
}

// --- B. AUTO-WIRING (Nalezení vazeb v kódu) ---
$allVazby = $db->run('synapse_vazby', 'select');
foreach ($allVazby as $v) {
    if (($v['popis'] ?? '') === 'auto-scan') {
        $db->run('synapse_vazby', 'delete', ['id' => $v['id']]);
    }
}

$knownNodes = array_column($db->run('synapse_uzly', 'select'), 'id');
$addedEdges = [];
$link = function($z, $do) use ($db, &$addedEdges, $knownNodes) {
    if(!in_array($z, $knownNodes) || !in_array($do, $knownNodes)) return;
    $hash = md5($z . '_' . $do);
    if(isset($addedEdges[$hash])) return;
    $addedEdges[$hash] = true;
    $db->run('synapse_vazby', 'insert', ['z_uzlu' => $z, 'do_uzlu' => $do, 'popis' => 'auto-scan', 'aktivni' => true]);
};

// 1. JS -> API (Hledá volání PC.api)
if ($uiJs && preg_match_all('/PC\.api\(\s*[\'"]([a-z0-9_]+)[\'"]\s*,\s*[\'"]([a-z0-9_]+)[\'"]/', $uiJs, $jsM)) {
    foreach ($jsM[1] as $i => $modSlug) {
        $apiId = "api_{$modSlug}_{$jsM[2][$i]}";
        $link('js_PC_api', $apiId); // Výchozí pro PC.api konektor
    }
}

// 2. HTML -> JS a API (Hledá onclick a form akce v komponentách)
foreach ($htmlUzly as $hId => $htmlCode) {
    // Vazby HTML -> JS (např. onclick="PC.router.load(...)")
    if (preg_match_all('/(onclick|onsubmit|onchange)="([^"]*PC\.([a-zA-Z0-9_]+)[^"]*)"/i', $htmlCode, $evM)) {
        foreach ($evM[3] as $jsCall) {
            $link($hId, 'js_PC_' . $jsCall);
        }
    }
    // Specifické volání API z HTML (inline)
    if (preg_match_all('/PC\.api\(\s*[\'"]([a-z0-9_]+)[\'"]\s*,\s*[\'"]([a-z0-9_]+)[\'"]/', $htmlCode, $apiM)) {
        foreach ($apiM[1] as $i => $modSlug) {
            $link($hId, "api_{$modSlug}_{$apiM[2][$i]}");
        }
    }
}

// 3. API -> DB
$uzly = $db->run('synapse_uzly', 'select');
foreach ($uzly as $u) {
    if ($u['typ'] === 'api' && !empty($u['kod'])) {
        // Hledá $db->run('nazev_tabulky'...)
        if (preg_match_all("/->run\(\s*['\"]([a-z0-9_]+)['\"]/", $u['kod'], $dbM)) {
            foreach ($dbM[1] as $tbl) $link($u['id'], "db_$tbl");
        }
    }
}

function _getDefaultPopis(string $typ, string $id, string $nazev): string {
    switch ($typ) {
        case 'modul':      return "Modul platformy '{$nazev}'. Obsahuje UI render a API handlery.";
        case 'api':        return "PHP API endpoint '{$nazev}'. Přijímá \$data z gateway.";
        case 'db_tabulka': return "JSON databázová tabulka '{$nazev}'.";
        case 'js':         return "JavaScript funkce/objekt '{$nazev}'. Součást frontendové logiky.";
        case 'css_var':    return "CSS design token '{$nazev}'.";
        case 'abstraktni': return "Abstraktní logický koncept v architektuře.";
        default:           return "Uzel '{$nazev}' (typ: {$typ}).";
    }
}

return ['message' => 'Systém rozkouskován na fragmenty a vazby zmapovány.', 'celkem' => count($knownNodes),'message' => 'Ekosystém zmapován.', 'celkem' => count($nowIds), 'pridano' => count($added), 'odebrano' => count($removed)];