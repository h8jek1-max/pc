<?php
$db   = DB::getInstance();
$akce = (string)($data['akce'] ?? 'list');
switch ($akce) {
    case 'list':
        return $db->run('builder_projekty', 'select', ['orderBy' => 'updated_at']);
    case 'save':
        $id     = (string)($data['id']     ?? '');
        $nazev  = trim((string)($data['nazev']  ?? 'Bez názvu'));
        $canvas = (string)($data['canvas'] ?? '');
        $nodes  = is_array($data['nodes']  ?? null) ? $data['nodes'] : [];
        if ($id) {
            $db->run('builder_projekty', 'update', ['id' => $id, 'data' => ['nazev' => $nazev, 'canvas' => $canvas, 'nodes' => $nodes]]);
            return $id;
        } else {
            return $db->run('builder_projekty', 'insert', ['nazev' => $nazev, 'canvas' => $canvas, 'nodes' => $nodes]);
        }
    case 'load':
        $id = (string)($data['id'] ?? '');
        $r  = $db->run('builder_projekty', 'select', ['where' => ['id' => $id]]);
        if (empty($r)) throw new Exception("Projekt '$id' nenalezen.");
        return $r[0];
    case 'delete':
        $id = (string)($data['id'] ?? '');
        $db->run('builder_projekty', 'delete', ['id' => $id]);
        return ['message' => 'Smazáno.'];
    default:
        throw new Exception("Neznámá akce projects: $akce");
}