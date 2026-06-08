<?php
$db = DB::getInstance();
$id = (string)($data['id'] ?? '');
if (!$id) throw new Exception('Chybí ID uzlu.');

$uzly = $db->run('synapse_uzly', 'select', ['where' => ['id' => $id]]);
if (empty($uzly)) throw new Exception("Uzel '$id' nenalezen.");
$uzel = $uzly[0];
$vlastnosti = $db->run('synapse_vlastnosti', 'select', ['where' => ['uzel_id' => $id]]);
$uzel['vlastnosti'] = $vlastnosti;

if (empty($uzel['kod'])) {
    $uzel['kod'] = _loadSourceCode($id, $uzel['typ']);
}

$vazby = $db->run('synapse_vazby', 'select');
$fyzicka = array_filter($vazby, fn($v) => $v['z_uzlu'] === $id || $v['do_uzlu'] === $id);
$fyzicka = array_values($fyzicka);

$doporucene = _getDoporucena($id, $uzel, $db);
$pocetSpojeni = count($fyzicka);

return [
    'uzel'            => $uzel,
    'fyzicka_spojeni' => $fyzicka,
    'doporucene'      => array_values($doporucene),
    'pocet_spojeni'   => $pocetSpojeni,
];

function _loadSourceCode(string $id, string $typ): string {
    switch ($typ) {
        case 'api':
            if (preg_match('/^api_([a-z0-9_]+)_([a-z0-9_]+)$/', $id, $m)) {
                $f = PC_ROOT . "/php/mod/{$m[1]}/api/{$m[2]}.php";
                return file_exists($f) ? file_get_contents($f) : '';
            }
            break;
        case 'modul':
            $slug = str_replace('mod_', '', $id);
            $f    = PC_ROOT . "/php/mod/{$slug}/module.php";
            return file_exists($f) ? file_get_contents($f) : '';
        case 'js':
            $f = PC_ROOT . '/js/ui.js';
            if (file_exists($f)) {
                $js     = file_get_contents($f);
                $fnName = str_replace(['js_PC_', '_'], ['PC.', '.'], $id);
                preg_match('/' . preg_quote($fnName, '/') . '[^}]+\{[^}]+\}/', $js, $mm);
                return $mm[0] ?? '// Viz js/ui.js — ' . $fnName;
            }
            break;
        case 'css_var':
            $varName = str_replace(['css_', '_'], ['--', '-'], $id);
            $f = PC_ROOT . '/css/ui.css';
            if (file_exists($f)) {
                $css = file_get_contents($f);
                preg_match('/' . preg_quote($varName, '/') . '\s*:[^;]+;/', $css, $mm);
                return $mm[0] ?? '';
            }
            break;
    }
    return '';
}

function _getDoporucena(string $id, array $uzel, DB $db): array {
    $all = $db->run('synapse_uzly', 'select');
    $existVazby = $db->run('synapse_vazby', 'select');
    $existIds   = [];
    foreach ($existVazby as $v) {
        if ($v['z_uzlu'] === $id) $existIds[] = $v['do_uzlu'];
        if ($v['do_uzlu'] === $id) $existIds[] = $v['z_uzlu'];
    }
    $existIds[] = $id;

    $doporucene = [];
    $typ = $uzel['typ'];
    foreach ($all as $u) {
        if (in_array($u['id'], $existIds)) continue;
        $add = false;
        switch ($typ) {
            case 'api':
                if ($u['typ'] === 'db_tabulka') $add = true;
                if ($u['typ'] === 'abstraktni') $add = true;
                break;
            case 'modul':
                if ($u['typ'] === 'api' && str_contains($u['id'], str_replace('mod_', '', $id))) $add = true;
                if ($u['typ'] === 'db_tabulka') $add = true;
                break;
            case 'js':
                if ($u['typ'] === 'api') $add = true;
                if ($u['id'] === 'abs_ui_akce') $add = true;
                break;
            case 'css_var':
                if ($u['typ'] === 'css_var') $add = true;
                break;
            case 'db_tabulka':
                if ($u['typ'] === 'api') $add = true;
                break;
            case 'abstraktni':
                if ($u['typ'] !== 'abstraktni') $add = true;
                break;
        }
        if ($add) $doporucene[$u['id']] = $u;
        if (count($doporucene) >= 12) break;
    }
    return $doporucene;
}