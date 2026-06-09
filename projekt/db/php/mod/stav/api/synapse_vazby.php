<?php
$db   = DB::getInstance();
$akce = (string)($data['akce'] ?? 'list');
switch ($akce) {
    case 'create':
        $z  = (string)($data['z_uzlu']  ?? '');
        $do = (string)($data['do_uzlu'] ?? '');
        if (!$z || !$do) throw new Exception('Chybí z_uzlu nebo do_uzlu.');
        $exist = $db->run('synapse_vazby', 'select', ['where' => ['z_uzlu' => $z, 'do_uzlu' => $do]]);
        if (!empty($exist)) throw new Exception('Tato vazba již existuje.');
        $newId = $db->run('synapse_vazby', 'insert', [
            'z_uzlu'  => $z,
            'do_uzlu' => $do,
            'popis'   => (string)($data['popis'] ?? ''),
            'aktivni' => true,
        ]);
        return ['id' => $newId, 'message' => 'Vazba vytvořena.'];
    case 'delete':
        $id = (string)($data['id'] ?? '');
        if (!$id) throw new Exception('Chybí ID vazby.');
        $db->run('synapse_vazby', 'delete', ['id' => $id]);
        return ['message' => 'Vazba smazána.'];
    case 'toggle':
        $id   = (string)($data['id'] ?? '');
        $vazby = $db->run('synapse_vazby', 'select', ['where' => ['id' => $id]]);
        if (empty($vazby)) throw new Exception('Vazba nenalezena.');
        $now = !(bool)($vazby[0]['aktivni'] ?? true);
        $db->run('synapse_vazby', 'update', ['id' => $id, 'data' => ['aktivni' => $now]]);
        return ['aktivni' => $now, 'message' => $now ? 'Vazba aktivována.' : 'Vazba deaktivována.'];
    case 'list':
    default:
        $uzl = (string)($data['uzel_id'] ?? '');
        if ($uzl) {
            $all = $db->run('synapse_vazby', 'select');
            return array_values(array_filter($all, fn($v) => $v['z_uzlu'] === $uzl || $v['do_uzlu'] === $uzl));
        }
        return $db->run('synapse_vazby', 'select');
}