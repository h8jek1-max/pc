<?php
// benchmark_compare.php
require __DIR__ . '/GraphDb.php';

class BenchmarkCompare
{
    private int $rows = 500;
    private array $results = [];
    
    public function run(): void
    {
        echo "\n=== SROVNÁVACÍ BENCHMARK ===\n";
        echo "Počet záznamů: " . $this->rows . "\n";
        echo str_repeat('=', 60) . "\n\n";
        
        $this->benchmarkGraphDb();
        $this->benchmarkSQLite();
        $this->benchmarkJson();
        
        $this->printResults();
    }
    
    private function benchmarkGraphDb(): void
    {
        echo "Testuji GraphDb...\n";
        $file = __DIR__ . '/bench_graph.db';
        @unlink($file);
        
        $db = new GraphDb($file);
        $db->query("CREATE TABLE users (id INT, name TEXT INDEX, age INT INDEX, bio TEXT)");
        
        // INSERT
        $start = microtime(true);
        for ($i = 1; $i <= $this->rows; $i++) {
            $name = "User" . $i;
            $age = rand(18, 80);
            $bio = $i % 20 === 0 ? str_repeat("Very long bio for user $i. ", 30) : "Bio $i";
            $db->query("INSERT INTO users (id, name, age, bio) VALUES ($i, '$name', $age, '$bio')");
        }
        $this->results['graphdb']['insert'] = microtime(true) - $start;
        
        // SELECT by ID
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $id = rand(1, $this->rows);
            $db->query("SELECT * FROM users WHERE id = $id");
        }
        $this->results['graphdb']['select_id'] = microtime(true) - $start;
        
        // SELECT by NAME (index)
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $id = rand(1, $this->rows);
            $db->query("SELECT * FROM users WHERE name = 'User$id'");
        }
        $this->results['graphdb']['select_index'] = microtime(true) - $start;
        
        // SELECT LIKE (našeptávač)
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $prefix = "User" . rand(1, 9);
            $db->query("SELECT name FROM users WHERE name LIKE '$prefix%'");
        }
        $this->results['graphdb']['select_like'] = microtime(true) - $start;
        
        // UPDATE
        $start = microtime(true);
        for ($i = 1; $i <= 50; $i++) {
            $id = rand(1, $this->rows);
            $db->query("UPDATE users SET age = " . rand(18, 80) . " WHERE id = $id");
        }
        $this->results['graphdb']['update'] = microtime(true) - $start;
        
        // DELETE
        $start = microtime(true);
        for ($i = 1; $i <= 30; $i++) {
            $id = rand(1, $this->rows);
            $db->query("DELETE FROM users WHERE id = $id");
        }
        $this->results['graphdb']['delete'] = microtime(true) - $start;
        
        // Velikost
        $this->results['graphdb']['size'] = filesize($file) / 1024;
        //@unlink($file);
        echo "  Hotovo\n";
    }
    
    private function benchmarkSQLite(): void
    {
        echo "Testuji SQLite...\n";
        $file = __DIR__ . '/bench_sqlite.db';
        @unlink($file);
        
        $pdo = new PDO("sqlite:$file");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, age INTEGER, bio TEXT)");
        $pdo->exec("CREATE INDEX idx_name ON users(name)");
        $pdo->exec("CREATE INDEX idx_age ON users(age)");
        
        // INSERT
        $start = microtime(true);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO users (id, name, age, bio) VALUES (?, ?, ?, ?)");
        for ($i = 1; $i <= $this->rows; $i++) {
            $name = "User$i";
            $age = rand(18, 80);
            $bio = $i % 20 === 0 ? str_repeat("Very long bio for user $i. ", 30) : "Bio $i";
            $stmt->execute([$i, $name, $age, $bio]);
        }
        $pdo->commit();
        $this->results['sqlite']['insert'] = microtime(true) - $start;
        
        // SELECT by ID
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $id = rand(1, $this->rows);
            $pdo->query("SELECT * FROM users WHERE id = $id")->fetchAll();
        }
        $this->results['sqlite']['select_id'] = microtime(true) - $start;
        
        // SELECT by NAME
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $id = rand(1, $this->rows);
            $pdo->query("SELECT * FROM users WHERE name = 'User$id'")->fetchAll();
        }
        $this->results['sqlite']['select_index'] = microtime(true) - $start;
        
        // SELECT LIKE
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $prefix = "User" . rand(1, 9);
            $pdo->query("SELECT name FROM users WHERE name LIKE '$prefix%'")->fetchAll();
        }
        $this->results['sqlite']['select_like'] = microtime(true) - $start;
        
        // UPDATE
        $start = microtime(true);
        for ($i = 1; $i <= 50; $i++) {
            $id = rand(1, $this->rows);
            $pdo->exec("UPDATE users SET age = " . rand(18, 80) . " WHERE id = $id");
        }
        $this->results['sqlite']['update'] = microtime(true) - $start;
        
        // DELETE
        $start = microtime(true);
        for ($i = 1; $i <= 30; $i++) {
            $id = rand(1, $this->rows);
            $pdo->exec("DELETE FROM users WHERE id = $id");
        }
        $this->results['sqlite']['delete'] = microtime(true) - $start;
        
        // VACUUM
        $start = microtime(true);
        $pdo->exec("VACUUM");
        $this->results['sqlite']['vacuum'] = microtime(true) - $start;
        
        $this->results['sqlite']['size'] = filesize($file) / 1024;
        //@unlink($file);
        echo "  Hotovo\n";
    }
    
    private function benchmarkJson(): void
    {
        echo "Testuji JSON...\n";
        $file = __DIR__ . '/bench_data.json';
        @unlink($file);
        
        $data = [];
        
        // INSERT
        $start = microtime(true);
        for ($i = 1; $i <= $this->rows; $i++) {
            $data[] = [
                'id' => $i,
                'name' => "User$i",
                'age' => rand(18, 80),
                'bio' => $i % 20 === 0 ? str_repeat("Very long bio for user $i. ", 30) : "Bio $i"
            ];
        }
        file_put_contents($file, json_encode($data));
        $this->results['json']['insert'] = microtime(true) - $start;
        
        // SELECT by ID (lineární)
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $id = rand(1, $this->rows);
            foreach ($data as $row) {
                if ($row['id'] == $id) break;
            }
        }
        $this->results['json']['select_id'] = microtime(true) - $start;
        
        // SELECT by NAME (lineární)
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $id = rand(1, $this->rows);
            $name = "User$id";
            foreach ($data as $row) {
                if ($row['name'] == $name) break;
            }
        }
        $this->results['json']['select_index'] = microtime(true) - $start;
        
        // SELECT LIKE (lineární)
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $prefix = "User" . rand(1, 9);
            $results = [];
            foreach ($data as $row) {
                if (str_starts_with($row['name'], $prefix)) {
                    $results[] = $row['name'];
                }
            }
        }
        $this->results['json']['select_like'] = microtime(true) - $start;
        
        // UPDATE
        $start = microtime(true);
        for ($i = 1; $i <= 50; $i++) {
            $id = rand(1, $this->rows);
            foreach ($data as &$row) {
                if ($row['id'] == $id) {
                    $row['age'] = rand(18, 80);
                    break;
                }
            }
        }
        file_put_contents($file, json_encode($data));
        $this->results['json']['update'] = microtime(true) - $start;
        
        // DELETE
        $start = microtime(true);
        for ($i = 1; $i <= 30; $i++) {
            $id = rand(1, $this->rows);
            foreach ($data as $key => $row) {
                if ($row['id'] == $id) {
                    unset($data[$key]);
                    break;
                }
            }
            $data = array_values($data);
        }
        file_put_contents($file, json_encode($data));
        $this->results['json']['delete'] = microtime(true) - $start;
        
        $this->results['json']['size'] = filesize($file) / 1024;
        //@unlink($file);
        echo "  Hotovo\n";
    }
    
    private function printResults(): void
    {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "VÝSLEDKY\n";
        echo str_repeat('=', 60) . "\n\n";
        
        $operations = [
            'insert' => 'INSERT (500x)',
            'select_id' => 'SELECT by ID (100x)',
            'select_index' => 'SELECT by INDEX (100x)',
            'select_like' => 'SELECT LIKE (100x)',
            'update' => 'UPDATE (50x)',
            'delete' => 'DELETE (30x)',
        ];
        
        echo str_pad("Operace", 25);
        echo str_pad("GraphDb", 15);
        echo str_pad("SQLite", 15);
        echo str_pad("JSON", 15) . "\n";
        echo str_repeat('-', 70) . "\n";
        
        foreach ($operations as $key => $label) {
            $g = isset($this->results['graphdb'][$key]) ? number_format($this->results['graphdb'][$key], 4) . 's' : '-';
            $s = isset($this->results['sqlite'][$key]) ? number_format($this->results['sqlite'][$key], 4) . 's' : '-';
            $j = isset($this->results['json'][$key]) ? number_format($this->results['json'][$key], 4) . 's' : '-';
            echo str_pad($label, 25);
            echo str_pad($g, 15);
            echo str_pad($s, 15);
            echo str_pad($j, 15) . "\n";
        }
        
        echo "\n";
        echo str_pad("Velikost souboru", 25);
        echo str_pad(($this->results['graphdb']['size'] ?? 0) . ' KB', 15);
        echo str_pad(($this->results['sqlite']['size'] ?? 0) . ' KB', 15);
        echo str_pad(($this->results['json']['size'] ?? 0) . ' KB', 15) . "\n";
        
        // Celkové časy
        $total_g = array_sum(array_intersect_key($this->results['graphdb'], $operations));
        $total_s = array_sum(array_intersect_key($this->results['sqlite'], $operations));
        $total_j = array_sum(array_intersect_key($this->results['json'], $operations));
        
        echo "\n";
        echo str_pad("CELKEM", 25);
        echo str_pad(number_format($total_g, 4) . 's', 15);
        echo str_pad(number_format($total_s, 4) . 's', 15);
        echo str_pad(number_format($total_j, 4) . 's', 15) . "\n";
        
        if ($total_g > 0 && $total_s > 0) {
            $ratio = $total_g / $total_s;
            echo "\nGraphDb je " . number_format($ratio, 2) . "x " . ($ratio > 1 ? "pomalejší" : "rychlejší") . " než SQLite\n";
        }
    }
}

$benchmark = new BenchmarkCompare();
$benchmark->run();
