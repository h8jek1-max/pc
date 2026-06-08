<?php
$db   = DB::getInstance();
$akce = (string)($data['akce'] ?? 'test_node');
switch ($akce) {
    case 'test_node':
        $uzelId = (string)($data['uzel_id'] ?? '');
        $vstup  = is_array($data['vstup'] ?? null) ? $data['vstup'] : [];
        if (!$uzelId) throw new Exception('Chybí uzel_id.');
        return _sandboxRun($uzelId, $vstup);

    case 'test_chain':
        $chain     = is_array($data['chain'] ?? null) ? $data['chain'] : [];
        $vstupInit = is_array($data['vstup'] ?? null) ? $data['vstup'] : [];
        if (empty($chain)) throw new Exception('Prázdný řetězec.');
        $results     = [];
        $aktuVystup  = $vstupInit;
        $celkemCas   = 0;
        foreach ($chain as $uzelId) {
            $r = _sandboxRun($uzelId, $aktuVystup);
            $results[]    = $r;
            $celkemCas   += $r['cas_ms'];
            if (is_array($r['vystup'])) $aktuVystup = $r['vystup'];
        }
        return [
            'kroky'      => $results,
            'celkem_cas' => $celkemCas,
            'final'      => end($results)['vystup'] ?? null,
            'stav'       => in_array('chyba', array_column($results, 'stav')) ? 'chyba' : 'ok',
        ];

    case 'generate_data':
        $uzelId = (string)($data['uzel_id'] ?? '');
        if (!$uzelId) throw new Exception('Chybí uzel_id.');
        $uzly = $db->run('synapse_uzly', 'select', ['where' => ['id' => $uzelId]]);
        if (empty($uzly)) throw new Exception("Uzel '$uzelId' nenalezen.");
        return _generateTestData($uzly[0]);

    case 'get_results':
        $uzelId = (string)($data['uzel_id'] ?? '');
        $limit  = (int)($data['limit'] ?? 20);
        $params = ['orderBy' => 'created_at', 'limit' => $limit];
        if ($uzelId) $params['where'] = ['uzel_id' => $uzelId];
        return $db->run('synapse_sandbox', 'select', $params);
        
    case 'clear':
        $uzelId = (string)($data['uzel_id'] ?? '');
        if ($uzelId) {
            $db->run('synapse_sandbox', 'delete', ['where' => ['uzel_id' => $uzelId]]);
        } else {
            file_put_contents(DB_PATH . '/json/synapse_sandbox.db', '');
            //file_put_contents(DB_PATH . '/json/synapse_sandbox.json', json_encode([]));
        }
        return ['message' => 'Sandbox vyčištěn.'];
        
    default:
        throw new Exception("Neznámá akce sandbox: $akce");
}

function _generateTestData(array $uzel): array {
    $typ   = $uzel['typ'] ?? '';
    $id    = $uzel['id']  ?? '';
    $forms = [];
    $recommended = [];
    switch ($typ) {
        case 'api':
            if (preg_match('/create|add|insert|save/', $id)) {
                $recommended = ['nazev' => 'Testovací položka ' . rand(1,99), 'hodnota' => rand(1,100), 'popis' => 'Auto-generovaný záznam'];
            } elseif (preg_match('/delete|remove/', $id)) {
                $recommended = ['id' => 'test_id_001'];
            } elseif (preg_match('/update|edit/', $id)) {
                $recommended = ['id' => 'test_id_001', 'data' => ['nazev' => 'Upravená hodnota']];
            } else {
                $recommended = ['where' => [], 'limit' => 10];
            }
            break;
        case 'js':
            $recommended = ['event' => 'click', 'target' => '#btn-test', 'payload' => []];
            break;
        case 'db_tabulka':
            $recommended = ['action' => 'select', 'params' => ['where' => [], 'limit' => 5]];
            break;
        default:
            $recommended = ['test' => true, 'timestamp' => date('Y-m-d H:i:s'), 'random' => rand(100,999)];
    }
    return [
        'uzel_id'     => $uzel['id'],
        'typ'         => $typ,
        'doporucena'  => $recommended,
        'forma'       => $forms,
        'popis_formy' => "Doporučená vstupní data pro tento typ.",
    ];
}