<?php



 require_once 'config.php';
require_once 'logger.php';
require_once 'db.php';
require_once 'registry.php';/* */

header('Content-Type: application/json; charset=utf-8');

try {
    $raw = file_get_contents('php://input');
	
    if ($raw === false || $raw === '') {
header('Content-Type: text/plain; charset=utf-8');
	echo $_SESSION['csrf_token'];
	exit;
} //echo $_SESSION['csrf_token']; //throw new Exception("Prázdné tělo požadavku.")

    $input = json_decode($raw, true);
    if (!is_array($input)) throw new Exception("Neplatný JSON vstup.");
    if (empty($input['module']) || empty($input['action'])) {
        throw new Exception("Chybí 'module' nebo 'action'.");
    }

    $action = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($input['action'] ?? ''));
    $mod    = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($input['module'] ?? '')));
    $data   = is_array($input['data'] ?? null) ? $input['data'] : [];

    // CSRF ochrana (vynecháme pro render a get_ akce)
$skipCsrf = in_array($action, [
    'render', 'get_logs', 'get_map', 'synapse_search', 'synapse_detail', 
    'components', 'projects', 'get_history_list', 'sandbox_test',
    'db_list_tables', 'db_get_data', 'db_get_graph_cell', 'db_get_history', 'db_get_help_wizard' // NAŠE NOVÉ ČTECÍ AKCE
], true);

    if (!$skipCsrf) {
        if (empty($input['csrf']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$input['csrf'])) {
            throw new Exception("Bezpečnostní chyba CSRF.");
        }
    }

    // ── Systémové akce ──────────────────────────────────────────
    if ($mod === 'system') {
        switch ($action) {
            case 'log_js':
                Logger::log("JS: " . ($data['msg'] ?? ''), 'error');
                echo json_encode(['success' => true]); exit;

            case 'get_logs':
                echo json_encode(['success' => true, 'data' => Logger::getRecent()]);
                exit;

            case 'get_map':
                echo json_encode(['success' => true, 'data' => _buildSystemMap()]);
                exit;

            case 'history_list':
                $files = glob(DB_PATH . '/his/*.db') ?: [];
                rsort($files);
                $list = array_map(fn($f) => [
                    'file'  => basename($f),
                    'size'  => filesize($f),
                    'mtime' => filemtime($f),
                ], array_slice($files, 0, 100));
                echo json_encode(['success' => true, 'data' => $list]); exit;

            case 'history_restore':
                $file = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $data['file'] ?? '');
                $src  = DB_PATH . '/his/' . $file;
                if (!file_exists($src)) throw new Exception("Záloha nenalezena: $file");
                preg_match('/^\d{8}_\d{6}_(.+)\.db$/', $file, $m);
                $tableName = $m[1] ?? null;
                if (!$tableName) throw new Exception("Nelze určit název tabulky ze zálohy.");
                copy($src, DB_PATH . '/json/' . $tableName . '.db');
                echo json_encode(['success' => true, 'message' => "Obnoveno z: $file"]); exit;
        }
    }
	
	
    // ── Modul Vizuálního Prohlížeče Databáze ─────────────────────
    if ($mod === 'dbpr') {
        $db = DB::getInstance();

        switch ($action) {
            
            // 1. SEZNAM VŠECH TABULEK, LOGŮ A DYNAMICKÝCH INDEXŮ
            case 'db_list_tables':
                $dirJson = DB_PATH . "/json";
                $dirLogy = DB_PATH . "/centralni_logy";
                
                $dbFiles  = glob($dirJson . '/*_*.db') ?: [];
                $tmpFiles = glob($dirJson . '/*.{tmp,clean}', GLOB_BRACE) ?: [];
                $statFiles = glob(DB_PATH . '/statistika_dotazu_*.json') ?: [];
                $logFiles  = glob($dirLogy . '/log_*.db') ?: [];

                $tabulky = [];
                foreach ($dbFiles as $file) {
                    if (preg_match('/\/([^\/]+)_(\d+)\.db$/', $file, $matches)) {
                        $jmeno = $matches[1];
                        if (!isset($tabulky[$jmeno])) {
                            $tabulky[$jmeno] = ['segmenty' => 0, 'velikost_bytes' => 0];
                        }
                        $tabulky[$jmeno]['segmenty']++;
                        $tabulky[$jmeno]['velikost_bytes'] += filesize($file);
                    }
                }

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'tabulky' => $tabulky,
                        'docasne_soubory' => array_map('basename', $tmpFiles),
                        'statistiky_samooptimalizace' => array_map('basename', $statFiles),
                        'centralni_logy' => array_map(fn($f) => ['jmeno' => basename($f), 'velikost_bytes' => filesize($f)], $logFiles)
                    ]
                ], JSON_UNESCAPED_UNICODE);
                exit;
            // 2. STRÁNKOVANÝ VÝPIS DAT S FILTRACÍ PRO PROHLÍŽEČ (Nyní bleskově přes proudové hledání)
            case 'db_get_search':
                $tabulka = (string)($data['table'] ?? '');
                if (empty($tabulka)) throw new Exception("Chybí název tabulky.");

                // Všechny parametry (limit, page, search_col, search_val) předáme rovnou do nové metody 'search'
                $vysledekHledani = $db->run($tabulka, 'search', $data);

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'records'          => $vysledekHledani['records'],
                        'celkem'           => $vysledekHledani['celkem'],
                        'stranek'          => $vysledekHledani['stranek'],
                        'aktualni_stranka' => $vysledekHledani['aktualni_stranka']
                    ]
                ], JSON_UNESCAPED_UNICODE);
                exit;

            // 2. STRÁNKOVANÝ VÝPIS DAT S POKROČILOU FILTRACÍ PRO PROHLÍŽEČ
            case 'db_get_data':
                $tabulka = (string)($data['table'] ?? '');
                $stranka = (int)($data['page'] ?? 1);
                $limit   = (int)($data['limit'] ?? 20);
                $hledanySloupec = trim((string)($data['search_col'] ?? ''));
                $hledanaHodnota = trim((string)($data['search_val'] ?? ''));

                if (empty($tabulka)) throw new Exception("Chybí název tabulky.");

                // 1. Z disku přes bajtový select vytáhneme základní surovou tabulku (RAM cache pozic)
                // Voláme bez 'where', abychom získali všechny platné řádky, které pak pro prohlížeč profiltrujeme
                // OPRAVA: Přidáme parametr ignore_idx, abychom pro visual manager vynutili kompletní načtení
$vsechnaData = $db->run($tabulka, 'select', ['ignore_idx' => true]);

                $filtrovanaData = [];

                if (!empty($hledanaHodnota)) {
                    $hledatMalymi = mb_strtolower($hledanaHodnota, 'UTF-8');

                    foreach ($vsechnaData as $radek) {
                        if (!is_array($radek)) continue;

                        if (!empty($hledanySloupec)) {
                            // --- VARIANTA A: HLEDÁME V KONKRÉTNÍM SLOUPCI ---
                            if (isset($radek[$hledanySloupec])) {
                                $bunka = $radek[$hledanySloupec];
                                
                                if (is_array($bunka)) {
                                    // Pokud sloupec (např. tags) obsahuje pole hodnot, prohledáme je
                                    $nalezenoVPoli = false;
                                    foreach ($bunka as $prvek) {
                                        if (strpos(mb_strtolower((string)$prvek, 'UTF-8'), $hledatMalymi) !== false) {
                                            $nalezenoVPoli = true;
                                            break;
                                        }
                                    }
                                    if ($nalezenoVPoli) {
                                        $filtrovanaData[] = $radek;
                                    }
                                } else {
                                    // Klasické prohledání textu (Ošetřeno proti Array to string conversion)
                                    $obsahBunky = mb_strtolower((string)$bunka, 'UTF-8');
                                    if (strpos($obsahBunky, $hledatMalymi) !== false) {
                                        $filtrovanaData[] = $radek;
                                    }
                                }
                            }
                        } else {
                            // --- VARIANTA B: HLEDÁME NAPŘÍČ VŠEMI SLOUPCI ---
                            $nalezenoVRadku = false;
                            foreach ($radek as $klic => $hodnotaBunky) {
                                if (strpos($klic, '_') === 0) continue;
                                
                                if (is_array($hodnotaBunky)) {
                                    foreach ($hodnotaBunky as $prvek) {
                                        if (strpos(mb_strtolower((string)$prvek, 'UTF-8'), $hledatMalymi) !== false) {
                                            $nalezenoVRadku = true;
                                            break;
                                        }
                                    }
                                } else {
                                    $obsahBunky = mb_strtolower((string)$hodnotaBunky, 'UTF-8');
                                    if (strpos($obsahBunky, $hledatMalymi) !== false) {
                                        $nalezenoVRadku = true;
                                    }
                                }
                                if ($nalezenoVRadku) break;
                            }
                            if ($nalezenoVRadku) {
                                $filtrovanaData[] = $radek;
                            }
                        }
                    }
                } else {
                    $filtrovanaData = $vsechnaData;
                }


                // Spočítáme celkový počet položek po filtraci
                $celkemPolozek = count($filtrovanaData);
                
                // Provedeme oříznutí stránky (Paginaci) pro UI prohlížeče
                $offset = ($stranka - 1) * $limit;
                $orezanaData = array_slice($filtrovanaData, $offset, $limit);

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'records' => $orezanaData,
                        'celkem' => $celkemPolozek,
                        'stranek' => ceil($celkemPolozek / $limit),
                        'aktualni_stranka' => $stranka
                    ]
                ], JSON_UNESCAPED_UNICODE);
                exit;


            // 3. ZÍSKÁNÍ TEXTU HODNOTY A VAZEB Z GRAFU PRO KONKRÉTNÍ BUŇKU (ID typu "2.")
            case 'db_get_graph_cell':
                $idHodnoty = (string)($data['id_hodnoty'] ?? '');
                if (empty($idHodnoty)) throw new Exception("Chybí ID hodnoty.");

                $dataUzlu = $db->ziskejHodnotuZGrafu($idHodnoty);
                
                if ($dataUzlu) {
                    echo json_encode([
                        'success' => true, 
                        'data' => [
                            'text' => $dataUzlu['hodnota'], 
                            'vazby_na_tabulky' => $dataUzlu['dotcene_tabulky']
                        ]
                    ], JSON_UNESCAPED_UNICODE);
                } else {
                    echo json_encode(['success' => false, 'error' => 'ID hodnoty nebylo v grafu nalezeno.']);
                }
                exit;

            // 4. ZÍSKÁNÍ CELÉ HISTORIE VERZÍ PRO DANÝ ZÁZNAM (AŽ 5 VERZÍ)
            case 'db_get_history':
                $tabulka = (string)($data['table'] ?? '');
                $id = (string)($data['id'] ?? '');
                if (empty($tabulka) || empty($id)) throw new Exception("Chybí povinné parametry.");

                $historie = $db->getHistory($tabulka, $id);

                echo json_encode([
                    'success' => true, 
                    'data' => [
                        'id' => $id, 
                        'tabulka' => $tabulka,
                        'verze' => $historie
                    ]
                ], JSON_UNESCAPED_UNICODE);
                exit;

            // 5. INLINE ÚPRAVA HODNOTY NEBO VYTVOŘENÍ RELACE V GRAFU (CHRÁNĚNO CSRF)
            case 'db_update_value':
                $tabulka   = (string)($data['table'] ?? '');
                $idZaznamu = (string)($data['id'] ?? '');
                $sloupec   = (string)($data['column'] ?? '');
                $novaHodnota = (string)($data['value'] ?? '');
                
                $isGraf      = ($data['is_graph'] ?? false) === true;
                $idHodnoty   = $data['id_val'] ?? null;
                $idTargetTab = $data['target_table_id'] ?? null;
                $targetField = $data['target_field_name'] ?? null;

                if (empty($tabulka) || empty($idZaznamu) || empty($sloupec)) {
                    throw new Exception("Nedostatečné údaje pro aktualizaci.");
                }

                if ($isGraf && $idHodnoty !== null) {
                    $idTabulky = $data['table_id'] ?? '1.';
                    $uspech = $db->aktualizujGrafVazeb($idTabulky, $tabulka, $sloupec, $idHodnoty, $novaHodnota, $idTargetTab, $targetField);
                    $db->run($tabulka, 'update', ['id' => $idZaznamu, 'data' => [$sloupec => $idHodnoty]]);
                } else {
                    $uspech = $db->run($tabulka, 'update', ['id' => $idZaznamu, 'data' => [$sloupec => $novaHodnota]]);
                }

                echo json_encode(['success' => (bool)$uspech, 'message' => 'Hodnota byla úspěšně upravena.']);
                exit;

            // 6. VYTVOŘENÍ STRUKTURY: ZALOŽENÍ SLOUPCE NEBO PROPOJENÍ SLOUPCŮ (CHRÁNĚNO CSRF)
            case 'db_create_schema':
                $tabulka = (string)($data['table'] ?? '');
                $task    = (string)($data['task'] ?? ''); 
                
                if (empty($tabulka) || empty($task)) throw new Exception("Chybí základní parametry schématu.");

                $params = ['task' => $task];

                if ($task === 'create_column') {
                    $params['column'] = (string)($data['column'] ?? '');
                    $params['default_value'] = (string)($data['default_value'] ?? '');
                } elseif ($task === 'link_columns') {
                    $params['column'] = (string)($data['column'] ?? '');
                    $params['target_table'] = (string)($data['target_table'] ?? '');
                    $params['target_column'] = (string)($data['target_column'] ?? '');
                }

                $uspech = $db->run($tabulka, 'schema', $params);
                echo json_encode(['success' => (bool)$uspech, 'message' => 'Struktura schématu upravena.']);
                exit;

            // 7. PŘEJMENOVÁNÍ SLOUPCE A ZÁPIS DO HISTORIE STRUKTURY GRAFU (CHRÁNĚNO CSRF)
            case 'db_rename_column':
                $tabulka    = (string)($data['table'] ?? '');
                $staryNazev = (string)($data['column'] ?? '');
                $novyNazev  = (string)($data['new_name'] ?? '');

                if (empty($tabulka) || empty($staryNazev) || empty($novyNazev)) {
                    throw new Exception("Chybí povinné parametry pro přejmenování.");
                }

                $uspech = $db->run($tabulka, 'schema', [
                    'task' => 'rename_column',
                    'column' => $staryNazev,
                    'new_name' => $novyNazev
                ]);

                echo json_encode(['success' => (bool)$uspech, 'message' => 'Sloupec úspěšně přejmenován.']);
                exit;

            // 8. ROBUSTNÍ ROLLBACK NA VYBRANOU VERZI Z HISTORIE (CHRÁNĚNO CSRF)
            case 'db_rollback_version':
                $tabulka    = (string)($data['table'] ?? '');
                $id         = (string)($data['id'] ?? '');
                $verzeIndex = (int)($data['version_index'] ?? 0);

                if (empty($tabulka) || empty($id)) throw new Exception("Chybí parametry pro Rollback.");

                $historie = $db->getHistory($tabulka, $id);
                if (!isset($historie[$verzeIndex])) throw new Exception("Vybraná historická verze neexistuje.");

                $staraData = $historie[$verzeIndex];
                unset($staraData['updated_at'], $staraData['_zmeneno'], $staraData['_autor_zmeny']);

                $uspech = $db->run($tabulka, 'update', ['id' => $id, 'data' => $staraData]);
                echo json_encode(['success' => (bool)$uspech, 'message' => 'Záznam byl obnoven ze starší verze.']);
                exit;

            // 9. PŘÍMÉ MAZÁNÍ ŘÁDKU (CHRÁNĚNO CSRF)
            case 'db_delete_row':
                $tabulka = (string)($data['table'] ?? '');
                $id      = (string)($data['id'] ?? '');
                if (empty($tabulka) || empty($id)) throw new Exception("Chybí ID pro smazání.");

                $uspech = $db->run($tabulka, 'delete', ['id' => $id]);
                echo json_encode(['success' => (bool)$uspech, 'message' => 'Záznam smazán.']);
                exit;

            // 10. MANUÁLNÍ SPUŠTĚNÍ CHYTRÉHO ČISTIČE (CHRÁNĚNO CSRF)
            case 'db_run_gc':
                $tabulka = (string)($data['table'] ?? '');
                if (empty($tabulka)) throw new Exception("Musíte vybrat tabulku pro úklid.");
$report = $db->garbageCollector($tabulka);
echo json_encode(['success' => true, 'data' => $report, 'message' => 'Optimalizace úspěšně dokončena.']);
exit;

            // OSTRÉ PROVEDENÍ DOTAZU VYKLIKANÉHO Z WIZARDU (CHRÁNĚNO CSRF)
            case 'db_execute_wizard_cmd':
                $targetTable = (string)($data['target_table'] ?? '');
                $targetAction = (string)($data['target_action'] ?? '');
                $targetParams = is_array($data['target_params'] ?? null) ? $data['target_params'] : [];

                if (empty($targetTable) || empty($targetAction)) {
                    throw new Exception("Neúplný příkaz pro provedení.");
                }

                // Bezpečnostní whitelist povolených akcí přes rozhraní wizardu
                if (!in_array($targetAction, ['select', 'insert', 'update', 'delete', 'schema'], true)) {
                    throw new Exception("Nepovolená databázová akce.");
                }

                // Spuštění vyklikaného dotazu přímo v našem novém bajtovém enginu!
                $vysledekAkce = $db->run($targetTable, $targetAction, $targetParams);

                echo json_encode([
                    'success' => true,
                    'data' => $vysledekAkce,
                    'message' => 'Příkaz byl na disku serveru úspěšně vykonán.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
// 11. GENERÁTOR NÁPOVĚDY A INTERAKTIVNÍHO QUERY BUILDERU PRO VÝVOJÁŘE
case 'db_get_help_wizard':
$tabulka = (string)($data['table'] ?? 'uzly');
$cestaGrafu = DB_PATH . "/globalni_graf_vazeb.json";
$strukturaGrafu = file_exists($cestaGrafu) ? json_decode(file_get_contents($cestaGrafu), true) : [];
$napoveda = [
'crud_operace' => [
'insert' => [
'kod' => "DB::getInstance()->run('{$tabulka}', 'insert', [\n 'odstín' => 'bílá',\n 'status' => 'aktivni'\n]);",
'popis' => "Vloží nový záznam, automaticky prováže s atomizovaným grafem a provede bleskový Append-only zápis."
],
'select_all' => [
'kod' => "DB::getInstance()->run('{$tabulka}', 'select');",
'popis' => "Načte všechny platné řádky z tabulky. Využívá pozice v RAM, zcela přeskakuje smazané verze."
],
'update' => [
'kod' => "DB::getInstance()->run('{$tabulka}', 'update', [\n 'id' => 'U1234',\n 'data' => ['status' => 'koncept']\n]);",
'popis' => "Append-only aktualizace. Připíše novou verzi na konec. Stará verze zůstane v historii (max 5)."
]
],
'schémata_a_grafy' => [
'create_column' => [
'kod' => "DB::getInstance()->run('{$tabulka}', 'schema', [\n 'task' => 'create_column',\n 'column' => 'odstín',\n 'default_value' => 'bílá'\n]);",
'popis' => "Založí nový sloupec v schématu. Přidělí mu v grafu unikátní ID (např. C_1.), čímž předchází konfliktům jmen."
],
'link_columns' => [
'kod' => "DB::getInstance()->run('{$tabulka}', 'schema', [\n 'task' => 'link_columns',\n 'column' => 'odstín',\n 'target_table' => 'vlastnosti',\n 'target_column' => 'barva'\n]);",
'popis' => "Strukturálně prováže celý sloupec s jinou tabulkou v globálním grafu."
]
]
];
echo json_encode([
'success' => true,
'data' => [
'aktualni_tabulka' => $tabulka,
'surovy_graf_vazeb' => $strukturaGrafu,
'wizard' => $napoveda
]
], JSON_UNESCAPED_UNICODE);
exit;
}
}


    if ($action === 'sandbox_test') {
        $result = _sandboxRun($mod, $data);
        echo json_encode(['success' => true, 'data' => $result]); exit;
    }

    if ($action === 'save_ide_node') {
        $id    = $data['id'] ?? null;
        if (!$id) throw new Exception('Chybí ID uzlu.');
        $db = DB::getInstance();
        $db->run('synapse_uzly', 'upsert', [
            'id'    => $id,
            'typ'   => $data['typ'] ?? 'vlastni',
            'nazev' => $data['nazev'] ?? $id,
            'kod'   => $data['kod'] ?? '',
            'popis' => $data['popis'] ?? '',
        ]);
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'deploy_chain') {
        $chain = $data['chain'] ?? '';
        $logs  = ["Analýza řetězce: $chain"];
        $parts = explode('->', $chain);
        foreach ($parts as $p) {
            $p = trim($p);
            if (preg_match('/^api_([a-z0-9_]+)_([a-z0-9_]+)$/', $p, $mm)) {
                $mMod = $mm[1];
                $mAct = $mm[2];
                $dir  = PC_ROOT . "/php/mod/{$mMod}/api";
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                $f = "$dir/{$mAct}.php";
                if (!file_exists($f)) {
                    file_put_contents($f, "<?php\n// Auto-generováno deploym\nreturn ['status'=>'ok','uzel'=>'{$p}'];\n");
                    $logs[] = "Vytvořen endpoint: php/mod/{$mMod}/api/{$mAct}.php";
                }
            }
        }
        echo json_encode(['success' => true, 'logs' => $logs]);
        exit;
    }

    if ($action === 'render') {
    $handlerFile = PC_ROOT . "/php/mod/{$mod}/api/{$action}.php";
    if (!file_exists($handlerFile)) {
        throw new Exception("API endpoint neexistuje: $mod/$action ");
    }
    $modules = Registry::getModules();
    $result = include $handlerFile;
    $result['module'] = $modules[$mod];
    echo json_encode(['success' => true, 'data' => $result]);
        exit;
    }

    $handlerFile = PC_ROOT . "/php/mod/{$mod}/api/{$action}.php";
    if (!file_exists($handlerFile)) {
        throw new Exception("API endpoint neexistuje: $mod/$action ");
    }
    $result = include $handlerFile;
    echo json_encode(['success' => true, 'data' => $result]);
} catch (Exception $e) {
    Logger::error("API Gateway: " . $e->getMessage(), $e);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function _buildSystemMap(): array {
    $modules = Registry::getModules();
    return [
        'meta' => [
            'platform'  => 'Přírodní Civilizace OS',
            'version'   => PC_VERSION,
            'generated' => date('Y-m-d H:i:s'),
        ],
        'ideology' => [
            'vision'      => 'Post-monetární operační systém pro distribuci zdrojů na základě biologické nutnosti a přírodních limitů.',
            'principles'  => [
                'Každý uzel systému (API, UI, CSS, DB) je mapován jako Synapse uzel.',
                'Vše je testovatelné izolovaně i v řetězci před nasazením.',
                'Historie všech změn je dostupná.',
                'Stavebnice je hlavní vstupní bod pro rozšíření.',
            ],
        ],
        'architecture' => [
            'pattern'      => 'Monolitická SPA s modulárním backendem.',
            'layers' => [
                ['name' => 'Frontend SPA',    'file' => 'lib/ui.js'],
                ['name' => 'Stylování',       'file' => 'lib/ui.css'],
                ['name' => 'API Gateway',     'file' => 'core/api.php'],
                ['name' => 'DB Engine',       'file' => 'core/db.php'],
                ['name' => 'Logger',          'file' => 'core/logger.php'],
                ['name' => 'Registry',        'file' => 'core/registry.php'],
                ['name' => 'Stavebnice modul',   'file' => 'php/mod/stav/'],
            ],
        ],
        'synapse_system' => [
            'concept'  => 'Každý prvek platformy je reprezentován jako UZEL v grafu Synapse.',
        ],
    ];
}

function _sandboxRun(string $uzelId, array $vstupy): array {
    $db     = DB::getInstance();
    $start  = microtime(true);
    $result = ['uzel_id' => $uzelId, 'vstup' => $vstupy, 'vystup' => null, 'stav' => 'ok', 'cas_ms' => 0, 'log' => []];
    $uzly = $db->run('synapse_uzly', 'select', ['where' => ['id' => $uzelId]]);
    if (empty($uzly)) {
        $result['stav'] = 'chyba';
        $result['log'][] = "Uzel '$uzelId' nenalezen v Synapse.";
        return $result;
    }
    $uzel = $uzly[0];
    $result['log'][] = "Uzel nalezen: [{$uzel['typ']}] {$uzel['nazev']}";
    if ($uzel['typ'] === 'api' && preg_match('/^api_([a-z0-9_]+)_([a-z0-9_]+)$/', $uzelId, $m)) {
        $handlerFile = PC_ROOT . "/php/mod/{$m[1]}/api/{$m[2]}.php";
        if (file_exists($handlerFile)) {
            try {
                $data   = $vstupy;
                ob_start();
                $output = include $handlerFile;
                $obOut  = ob_get_clean();
                $result['vystup']   = $output;
                $result['echo_out'] = $obOut;
                $result['log'][]    = "Handler úspěšně spuštěn: $handlerFile";
            } catch (Throwable $e) {
                $result['stav']  = 'chyba';
                $result['log'][] = "Chyba při spuštění: " . $e->getMessage();
            }
        } else {
            $result['log'][] = "Handler nenalezen: $handlerFile (simulační mód)";
            $result['vystup'] = ['simulated' => true, 'vstup_prijat' => $vstupy];
        }
    } elseif ($uzel['typ'] === 'js') {
        $result['log'][]  = "JS uzly nelze spustit server-side. Použijte Browser Sandbox.";
        $result['vystup'] = ['info' => 'client_side_only'];
    } else {
        $result['log'][]  = "Uzel typu '{$uzel['typ']}' — simulační výstup.";
        $result['vystup'] = ['typ' => $uzel['typ'], 'nazev' => $uzel['nazev'], 'vstup' => $vstupy];
    }

    $result['cas_ms'] = round((microtime(true) - $start) * 1000, 2);
    $db->run('synapse_sandbox', 'insert', [
        'uzel_id'  => $uzelId,
        'vstup'    => json_encode($vstupy, JSON_UNESCAPED_UNICODE),
        'vystup'   => json_encode($result['vystup'], JSON_UNESCAPED_UNICODE),
        'stav'     => $result['stav'],
        'cas_ms'   => $result['cas_ms'],
        'log'      => implode(' | ', $result['log']),
    ]);
    return $result;
}