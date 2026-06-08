<?php
$db      = DB::getInstance();
$q       = strtolower(trim((string)($data['query']      ?? '')));
$typF    = strtolower(trim((string)($data['typ']        ?? '')));
$skF     = strtolower(trim((string)($data['skupina']    ?? '')));
$katF    = strtolower(trim((string)($data['kategorie']  ?? '')));
$valF    = strtolower(trim((string)($data['hodnota']    ?? '')));

$uzly = $db->run('synapse_uzly', 'select');
if ($skF !== '')  $uzly = array_filter($uzly, fn($u) => strtolower($u['skupina'] ?? '') === $skF);
if ($typF !== '') $uzly = array_filter($uzly, fn($u) => strtolower($u['typ']     ?? '') === $typF);

if ($q !== '' || $katF !== '' || $valF !== '') {
    $vlastnosti = $db->run('synapse_vlastnosti', 'select');
    $propIdx = [];
    foreach ($vlastnosti as $v) $propIdx[$v['uzel_id']][] = $v;

    $uzly = array_filter($uzly, function (array $u) use ($q, $katF, $valF, $propIdx): bool {
        $props = $propIdx[$u['id']] ?? [];
        if ($katF !== '' || $valF !== '') {
            $match = false;
            foreach ($props as $p) {
                $pk = strtolower($p['kategorie'] ?? '');
                $pv = strtolower($p['hodnota']   ?? '');
                if ($katF !== '' && $valF !== '') { if ($pk === $katF && $pv === $valF) { $match = true; break; } }
                elseif ($katF !== '') { if ($pk === $katF) { $match = true; break; } }
                else { if ($pv === $valF) { $match = true; break; } }
            }
            if (!$match) return false;
        }
        if ($q !== '') {
            if (stripos($u['nazev'] ?? '', $q) !== false) return true;
            if (stripos($u['id']    ?? '', $q) !== false) return true;
            if (stripos($u['popis'] ?? '', $q) !== false) return true;
            foreach ($props as $p) {
                if (stripos($p['hodnota'] ?? '', $q) !== false) return true;
            }
            return false;
        }
        return true;
    });
}

$vlastnosti = $db->run('synapse_vlastnosti', 'select');
$propIdx    = [];
foreach ($vlastnosti as $v) $propIdx[$v['uzel_id']][] = $v;

$result = [];
foreach (array_values($uzly) as $u) {
    $u['vlastnosti'] = $propIdx[$u['id']] ?? [];
    $result[] = $u;
}
return $result;