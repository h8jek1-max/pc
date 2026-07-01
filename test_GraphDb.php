<?php
// benchmark.php - srovnání MyUncompressedGraphDb vs SQLite vs JSON

require __DIR__ . '/GraphDb.php';

class Benchmark {
    private $results = [];
    private $dataSize = 1000; // počet záznamů
    
    public function run() {
        echo "=== BENCHMARK: MyUncompressedGraphDb vs SQLite vs JSON ===\n\n";
        
        $this->benchmarkGraphDb();
        $this->benchmarkSQLite();
        $this->benchmarkJSON();
        
        $this->printResults();
    }
    
    private function benchmarkGraphDb() {
        echo "Testing GraphDb...\n";
        $dbFile = __DIR__ . '/GraphDb.db';
        @unlink($dbFile);
        
        $db = new GraphDb($dbFile);
        
        // CREATE TABLE
        $start = microtime(true);
        $db->query("CREATE TABLE test (id INT, jmeno TEXT INDEX(jmeno), email TEXT, datum TEXT, vek INT INDEX(vek), popis TEXT, obsah TEXT)");
        $this->results['graphdb']['create'] = microtime(true) - $start;
        
        // INSERT - 1000 záznamů
        $start = microtime(true);
        for ($i = 1; $i <= $this->dataSize; $i++) {
            $jmeno = "Jmeno" . $i;
            $email = "email{$i}@test.cz";
            $datum = date('Y-m-d', strtotime("-$i days"));
            $vek = rand(18, 80);
            $popis = "Popis cislo $i";
            $obsah = str_repeat("Lorem ipsum dolor sit amet. ", rand(1, 10));
            
            $sql = sprintf(
                "INSERT INTO test (id, jmeno, email, datum, vek, popis, obsah) VALUES (%d, '%s', '%s', '%s', %d, '%s', '%s')",
                $i,
                str_replace("'", "''", $jmeno),
                $email,
                $datum,
                $vek,
                str_replace("'", "''", $popis),
                str_replace("'", "''", $obsah)
            );
            $db->query($sql);
        }
        $this->results['graphdb']['insert'] = microtime(true) - $start;
        
        // SELECT by ID (primární klíč)
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $id = rand(1, $this->dataSize);
            $db->query("SELECT * FROM test WHERE id = $id");
        }
        $this->results['graphdb']['select_by_id'] = microtime(true) - $start;
        
        // SELECT by INDEX (jméno - radix)
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $id = rand(1, $this->dataSize);
            $db->query("SELECT * FROM test WHERE jmeno = 'Jmeno$id'");
        }
        $this->results['graphdb']['select_by_index'] = microtime(true) - $start;
        
        // SELECT LIKE (pro našeptávač)
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $prefix = substr("Jmeno" . rand(1, $this->dataSize), 0, 4);
            $db->query("SELECT jmeno FROM test WHERE jmeno LIKE '$prefix%'");
        }
        $this->results['graphdb']['search_like'] = microtime(true) - $start;
        
        // SELECT BETWEEN (věk - btree)
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $from = rand(20, 40);
            $to = $from + rand(5, 15);
            $db->query("SELECT * FROM test WHERE vek BETWEEN $from AND $to");
        }
        $this->results['graphdb']['select_between'] = microtime(true) - $start;
        
        // SELECT WHERE TEXT CONTAINS (plnotextové vyhledávání)
        $start = microtime(true);
        for ($i = 1; $i <= 50; $i++) {
            $word = ['Lorem', 'ipsum', 'dolor', 'sit', 'amet'][rand(0, 4)];
            $db->query("SELECT * FROM test WHERE obsah LIKE '%$word%'");
        }
        $this->results['graphdb']['search_text'] = microtime(true) - $start;
        
        // UPDATE
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $id = rand(1, $this->dataSize);
            $db->query("UPDATE test SET vek = " . rand(18, 80) . " WHERE id = $id");
        }
        $this->results['graphdb']['update'] = microtime(true) - $start;
        
        // DELETE
        $start = microtime(true);
        for ($i = 1; $i <= 50; $i++) {
            $id = rand(1, $this->dataSize);
            $db->query("DELETE FROM test WHERE id = $id");
        }
        $this->results['graphdb']['delete'] = microtime(true) - $start;
        
        // UPSERT
        $start = microtime(true);
        for ($i = 1; $i <= 50; $i++) {
            $id = $this->dataSize + $i;
            $db->query("UPSERT INTO test (id, jmeno, email, datum, vek, popis, obsah) VALUES ($id, 'Novy$i', 'novy$i@test.cz', '2024-01-01', 30, 'Novy popis', 'Novy obsah') KEY (id)");
        }
        $this->results['graphdb']['upsert'] = microtime(true) - $start;
        
        // BLOB (velký text)
        $start = microtime(true);
        for ($i = 1; $i <= 20; $i++) {
            $bigText = str_repeat("Velmi dlouhý text pro BLOB test. ", 100);
            $db->query("UPDATE test SET obsah = '" . str_replace("'", "''", $bigText) . "' WHERE id = " . rand(1, $this->dataSize));
        }
        $this->results['graphdb']['blob'] = microtime(true) - $start;
        
        // OPTIMIZE
        $start = microtime(true);
        $db->query("OPTIMIZE TABLE test");
        $this->results['graphdb']['optimize'] = microtime(true) - $start;
        
        $db->__destruct();
        //@unlink($dbFile);
        echo "  Done.\n";
    }
    
    private function benchmarkSQLite() {
        echo "Testing SQLite...\n";
        $dbFile = __DIR__ . '/bench_sqlite.db';
        @unlink($dbFile);
        
        $pdo = new PDO("sqlite:$dbFile");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // CREATE TABLE
        $start = microtime(true);
        $pdo->exec("CREATE TABLE test (id INTEGER PRIMARY KEY, jmeno TEXT, email TEXT, datum TEXT, vek INTEGER, popis TEXT, obsah TEXT)");
        $pdo->exec("CREATE INDEX idx_jmeno ON test(jmeno)");
        $pdo->exec("CREATE INDEX idx_vek ON test(vek)");
        $this->results['sqlite']['create'] = microtime(true) - $start;
        
        // INSERT
        $start = microtime(true);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO test (id, jmeno, email, datum, vek, popis, obsah) VALUES (?, ?, ?, ?, ?, ?, ?)");
        for ($i = 1; $i <= $this->dataSize; $i++) {
            $stmt->execute([
                $i,
                "Jmeno$i",
                "email{$i}@test.cz",
                date('Y-m-d', strtotime("-$i days")),
                rand(18, 80),
                "Popis cislo $i",
                str_repeat("Lorem ipsum dolor sit amet. ", rand(1, 10))
            ]);
        }
        $pdo->commit();
        $this->results['sqlite']['insert'] = microtime(true) - $start;
        
        // SELECT by ID
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $id = rand(1, $this->dataSize);
            $pdo->query("SELECT * FROM test WHERE id = $id")->fetchAll();
        }
        $this->results['sqlite']['select_by_id'] = microtime(true) - $start;
        
        // SELECT by INDEX
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $id = rand(1, $this->dataSize);
            $pdo->query("SELECT * FROM test WHERE jmeno = 'Jmeno$id'")->fetchAll();
        }
        $this->results['sqlite']['select_by_index'] = microtime(true) - $start;
        
        // SELECT LIKE
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $prefix = substr("Jmeno" . rand(1, $this->dataSize), 0, 4);
            $pdo->query("SELECT jmeno FROM test WHERE jmeno LIKE '$prefix%'")->fetchAll();
        }
        $this->results['sqlite']['search_like'] = microtime(true) - $start;
        
        // SELECT BETWEEN
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $from = rand(20, 40);
            $to = $from + rand(5, 15);
            $pdo->query("SELECT * FROM test WHERE vek BETWEEN $from AND $to")->fetchAll();
        }
        $this->results['sqlite']['select_between'] = microtime(true) - $start;
        
        // SELECT TEXT CONTAINS
        $start = microtime(true);
        for ($i = 1; $i <= 50; $i++) {
            $word = ['Lorem', 'ipsum', 'dolor', 'sit', 'amet'][rand(0, 4)];
            $pdo->query("SELECT * FROM test WHERE obsah LIKE '%$word%'")->fetchAll();
        }
        $this->results['sqlite']['search_text'] = microtime(true) - $start;
        
        // UPDATE
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $id = rand(1, $this->dataSize);
            $pdo->exec("UPDATE test SET vek = " . rand(18, 80) . " WHERE id = $id");
        }
        $this->results['sqlite']['update'] = microtime(true) - $start;
        
        // DELETE
        $start = microtime(true);
        for ($i = 1; $i <= 50; $i++) {
            $id = rand(1, $this->dataSize);
            $pdo->exec("DELETE FROM test WHERE id = $id");
        }
        $this->results['sqlite']['delete'] = microtime(true) - $start;
        
        // UPSERT (INSERT OR REPLACE)
        $start = microtime(true);
        for ($i = 1; $i <= 50; $i++) {
            $id = $this->dataSize + $i;
            $pdo->exec("INSERT OR REPLACE INTO test (id, jmeno, email, datum, vek, popis, obsah) VALUES ($id, 'Novy$i', 'novy$i@test.cz', '2024-01-01', 30, 'Novy popis', 'Novy obsah')");
        }
        $this->results['sqlite']['upsert'] = microtime(true) - $start;
        
        // BLOB (velký text)
        $start = microtime(true);
        for ($i = 1; $i <= 20; $i++) {
            $bigText = str_repeat("Velmi dlouhý text pro BLOB test. ", 100);
            $pdo->exec("UPDATE test SET obsah = '$bigText' WHERE id = " . rand(1, $this->dataSize));
        }
        $this->results['sqlite']['blob'] = microtime(true) - $start;
        
        // VACUUM (ekvivalent OPTIMIZE)
        $start = microtime(true);
        $pdo->exec("VACUUM");
        $this->results['sqlite']['optimize'] = microtime(true) - $start;
        
        //@unlink($dbFile);
        echo "  Done.\n";
    }
    
    private function benchmarkJSON() {
        echo "Testing JSON file...\n";
        $jsonFile = __DIR__ . '/bench_data.json';
        
        $data = [];
        
        // CREATE (vytvoření souboru)
        $start = microtime(true);
        // INSERT
        for ($i = 1; $i <= $this->dataSize; $i++) {
            $data[] = [
                'id' => $i,
                'jmeno' => "Jmeno$i",
                'email' => "email{$i}@test.cz",
                'datum' => date('Y-m-d', strtotime("-$i days")),
                'vek' => rand(18, 80),
                'popis' => "Popis cislo $i",
                'obsah' => str_repeat("Lorem ipsum dolor sit amet. ", rand(1, 10))
            ];
        }
        file_put_contents($jsonFile, json_encode($data));
        $this->results['json']['insert'] = microtime(true) - $start;
        
        // SELECT by ID (lineární hledání)
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $id = rand(1, $this->dataSize);
            foreach ($data as $row) {
                if ($row['id'] == $id) { break; }
            }
        }
        $this->results['json']['select_by_id'] = microtime(true) - $start;
        
        // SELECT by INDEX (simulace - lineární)
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $id = rand(1, $this->dataSize);
            $jmeno = "Jmeno$id";
            foreach ($data as $row) {
                if ($row['jmeno'] == $jmeno) { break; }
            }
        }
        $this->results['json']['select_by_index'] = microtime(true) - $start;
        
        // SELECT LIKE (našeptávač)
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $prefix = substr("Jmeno" . rand(1, $this->dataSize), 0, 4);
            $results = [];
            foreach ($data as $row) {
                if (str_starts_with($row['jmeno'], $prefix)) {
                    $results[] = $row['jmeno'];
                }
            }
        }
        $this->results['json']['search_like'] = microtime(true) - $start;
        
        // SELECT BETWEEN (věk)
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $from = rand(20, 40);
            $to = $from + rand(5, 15);
            $results = [];
            foreach ($data as $row) {
                if ($row['vek'] >= $from && $row['vek'] <= $to) {
                    $results[] = $row;
                }
            }
        }
        $this->results['json']['select_between'] = microtime(true) - $start;
        
        // SEARCH TEXT
        $start = microtime(true);
        for ($i = 1; $i <= 50; $i++) {
            $word = ['Lorem', 'ipsum', 'dolor', 'sit', 'amet'][rand(0, 4)];
            $results = [];
            foreach ($data as $row) {
                if (str_contains($row['obsah'], $word)) {
                    $results[] = $row;
                }
            }
        }
        $this->results['json']['search_text'] = microtime(true) - $start;
        
        // UPDATE
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $id = rand(1, $this->dataSize);
            foreach ($data as &$row) {
                if ($row['id'] == $id) {
                    $row['vek'] = rand(18, 80);
                    break;
                }
            }
        }
        file_put_contents($jsonFile, json_encode($data));
        $this->results['json']['update'] = microtime(true) - $start;
        
        // DELETE
        $start = microtime(true);
        for ($i = 1; $i <= 50; $i++) {
            $id = rand(1, $this->dataSize);
            foreach ($data as $key => $row) {
                if ($row['id'] == $id) {
                    unset($data[$key]);
                    break;
                }
            }
            $data = array_values($data);
        }
        file_put_contents($jsonFile, json_encode($data));
        $this->results['json']['delete'] = microtime(true) - $start;
        
        // UPSERT
        $start = microtime(true);
        for ($i = 1; $i <= 50; $i++) {
            $id = $this->dataSize + $i;
            $found = false;
            foreach ($data as &$row) {
                if ($row['id'] == $id) {
                    $row['jmeno'] = "Novy$i";
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $data[] = [
                    'id' => $id,
                    'jmeno' => "Novy$i",
                    'email' => "novy{$i}@test.cz",
                    'datum' => '2024-01-01',
                    'vek' => 30,
                    'popis' => 'Novy popis',
                    'obsah' => 'Novy obsah'
                ];
            }
        }
        file_put_contents($jsonFile, json_encode($data));
        $this->results['json']['upsert'] = microtime(true) - $start;
        
        // BLOB (velký text) - jen update
        $start = microtime(true);
        for ($i = 1; $i <= 20; $i++) {
            $id = rand(1, $this->dataSize);
            $bigText = str_repeat("Velmi dlouhý text pro BLOB test. ", 100);
            foreach ($data as &$row) {
                if ($row['id'] == $id) {
                    $row['obsah'] = $bigText;
                    break;
                }
            }
        }
        file_put_contents($jsonFile, json_encode($data));
        $this->results['json']['blob'] = microtime(true) - $start;
        
        // "OPTIMIZE" - jen přepsání souboru
        $start = microtime(true);
        file_put_contents($jsonFile, json_encode($data));
        $this->results['json']['optimize'] = microtime(true) - $start;
        
        //@unlink($jsonFile);
        echo "  Done.\n";
    }
    
    private function printResults() {
        echo "\n=== VÝSLEDKY BENCHMARKU ===\n\n";
        echo "Počet záznamů: {$this->dataSize}\n\n";
        
        $operations = [
            'create' => 'CREATE TABLE',
            'insert' => 'INSERT (1000x)',
            'select_by_id' => 'SELECT by ID (100x)',
            'select_by_index' => 'SELECT by INDEX (100x)',
            'search_like' => 'SELECT LIKE (100x) - našeptávač',
            'select_between' => 'SELECT BETWEEN (100x)',
            'search_text' => 'TEXT SEARCH (50x)',
            'update' => 'UPDATE (100x)',
            'delete' => 'DELETE (50x)',
            'upsert' => 'UPSERT (50x)',
            'blob' => 'BLOB (20x)',
            'optimize' => 'OPTIMIZE/VACUUM'
        ];
        
        echo str_pad("Operace", 30) . str_pad("GraphDB", 15) . str_pad("SQLite", 15) . str_pad("JSON", 15) . "\n";
        echo str_repeat("-", 75) . "\n";
        
        foreach ($operations as $key => $label) {
            $g = isset($this->results['graphdb'][$key]) ? number_format($this->results['graphdb'][$key], 4) : '-';
            $s = isset($this->results['sqlite'][$key]) ? number_format($this->results['sqlite'][$key], 4) : '-';
            $j = isset($this->results['json'][$key]) ? number_format($this->results['json'][$key], 4) : '-';
            echo str_pad($label, 30) . str_pad($g . "s", 15) . str_pad($s . "s", 15) . str_pad($j . "s", 15) . "\n";
        }
        
        echo "\n=== SOUHRN ===\n";
        $total_g = array_sum($this->results['graphdb'] ?? []);
        $total_s = array_sum($this->results['sqlite'] ?? []);
        $total_j = array_sum($this->results['json'] ?? []);
        
        echo "GraphDB celkem: " . number_format($total_g, 4) . "s\n";
        echo "SQLite celkem: " . number_format($total_s, 4) . "s\n";
        echo "JSON celkem: " . number_format($total_j, 4) . "s\n";
        
        if ($total_g > 0 && $total_s > 0) {
            $ratio = $total_g / $total_s;
            echo "\nGraphDB je " . number_format($ratio, 2) . "x " . ($ratio > 1 ? "pomalejší" : "rychlejší") . " než SQLite\n";
        }
    }
}

// Spuštění benchmarku
$benchmark = new Benchmark();
$benchmark->run();
