<?php
$db = DB::getInstance();
$uzly = $db->run('synapse_uzly', 'select');
$logs = [];
$successCount = 0;

foreach ($uzly as $u) {
    if (empty($u['kod'])) continue;

    $path = null;
    if ($u['typ'] === 'api' && preg_match('/^api_([a-z0-9_]+)_([a-z0-9_]+)$/', $u['id'], $m)) {
        $path = PC_ROOT . "/php/mod/{$m[1]}/api/{$m[2]}.php";
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
    } 
    elseif ($u['typ'] === 'modul' && preg_match('/^mod_([a-z0-9_]+)$/', $u['id'], $m)) {
        $path = PC_ROOT . "/php/mod/{$m[1]}/module.php";
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
    }

    if ($path) {
        $kod = trim($u['kod']);
        // Zajištění, že PHP kód má správné tagy
        if (strpos($kod, '<?php') === false) {
            $kod = "<?php\n" . $kod;
        }
        
        file_put_contents($path, $kod);
        $successCount++;
        $logs[] = "✅ Zapsáno: " . str_replace(PC_ROOT, '', $path);
    }
}

return [
    'message' => "Platforma byla úspěšně sestavena. Přepsáno $successCount fyzických souborů z databázových fragmentů.",
    'logs' => $logs
];