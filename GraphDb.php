<?php
// GraphDb.php
declare(strict_types=1);

final class GraphDb
{
    // === Konstanty ===
    private const MAGIC = 'GDBV7';
    private const ZONE_A_ROW_LEN = 12;
    
    // Typy uzlů v Zóně A
    private const TYPE_HEADER = 3;      // rowid 0 - hlavička (Zóna C)
    private const TYPE_DATA = 0;        // datové řádky
    private const TYPE_TREE = 1;        // radix strom
    private const TYPE_BISECT = 2;      // B+Tree stránka
    private const TYPE_CACHE = 4;       // cache položka
    private const TYPE_FREE = 0xFFFFFFFF;
    
    private const BLOB_THRESHOLD = 255;
    private const HOLE_LIMIT = 5000;
    private const MICRO_HOLE = 4;
    private const CACHE_TTL = 300;
    private const CACHE_MAX_CHANGES = 2;
    private const CACHE_RESET_HOURS = 12;

    private string $path;
    private $fh;
    private array $header = [];
    private int $nextRowid = 1;
    private array $memoryIndex = [];
    private array $memoryCache = [];
    private array $freeRowids = [];      // Seznam volných rowidů v Zóně A

    public function __construct(string $path)
    {
        $this->path = $path;
        $exists = file_exists($path);
        $this->fh = fopen($path, $exists ? 'r+b' : 'w+b');
        if ($this->fh === false) {
            throw new RuntimeException("Nelze otevřít soubor: {$path}");
        }

        if (!$exists) {
            $this->initDatabase();
        } else {
            $this->loadHeader();
            $this->nextRowid = $this->getMaxRowid();
            $this->loadFreeRowids();
            $this->loadCacheFromDisk();
        }
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    // ======================================================================
    // INICIALIZACE
    // ======================================================================

    private function initDatabase(): void
    {
        [$diskType, $pageSize] = $this->detectHardware();

        $this->header = [
            'magic' => self::MAGIC,
            'version' => 7,
            'diskType' => $diskType,
            'pageSize' => $pageSize,
            'blobMode' => ($diskType === 'ssd'),
            'tables' => [],
            'cache' => [],
            'cacheDisabled' => false,
            'changeLog' => [],
            'freeRowids' => [],          // Volné rowidy v Zóně A
            'lastChange' => 0,
        ];

        $this->lockWrite(function () {
            $this->writeHeaderData();
        });
    }

    private function detectHardware(): array
    {
        $diskType = 'ssd';
        if (stripos(PHP_OS, 'WIN') === 0) {
            $cmd = 'powershell -Command "Get-PhysicalDisk | Select-Object -First 1 -ExpandProperty MediaType"';
            $out = @shell_exec($cmd);
            if ($out !== null && stripos(trim($out), 'HDD') !== false) {
                $diskType = 'hdd';
            }
        } else {
            $rotational = null;
            $devices = @glob('/sys/block/*/queue/rotational');
            if (is_array($devices)) {
                foreach ($devices as $file) {
                    if (preg_match('~/sys/block/(loop|ram|dm-)~', $file)) continue;
                    $val = @file_get_contents($file);
                    if ($val !== false) {
                        $rotational = trim($val);
                        break;
                    }
                }
            }
            if ($rotational === '1') $diskType = 'hdd';
        }
        return [$diskType, $diskType === 'hdd' ? 65536 : 4096];
    }

    // ======================================================================
    // ZÓNA C - HLAVIČKA (rowid 0)
    // ======================================================================

    private function writeHeaderData(): void
    {
        // 1. Serializujeme metadata
        $json = json_encode($this->header, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $data = pack('N', strlen($json)) . $json;
        
        // 2. Zjistíme starý offset
        fseek($this->fh, 0);
        $old = @unpack('Noffset/Nlength/Ntype', fread($this->fh, self::ZONE_A_ROW_LEN));
        
        // 3. Uvolníme staré místo jako díru
        if ($old && $old['type'] === self::TYPE_HEADER) {
            $this->addHole('_system', $old['offset'], $old['length']);
        }
        
        // 4. Zapíšeme nová metadata do Zóny B
        $offset = $this->writeData('_system', $data);
        $length = strlen($data);
        
        // 5. Aktualizujeme rowid 0 v Zóně A
        fseek($this->fh, 0);
        fwrite($this->fh, pack('N3', $offset, $length, self::TYPE_HEADER));
        fflush($this->fh);
    }

    private function loadHeader(): void
    {
        fseek($this->fh, 0);
        $raw = fread($this->fh, self::ZONE_A_ROW_LEN);
        if ($raw === false || strlen($raw) < self::ZONE_A_ROW_LEN) {
            throw new RuntimeException('Nelze načíst rowid 0');
        }
        
        $d = unpack('Noffset/Nlength/Ntype', $raw);
        if ($d['type'] !== self::TYPE_HEADER) {
            throw new RuntimeException('Rowid 0 neobsahuje hlavičku!');
        }
        
        fseek($this->fh, $d['offset']);
        $data = fread($this->fh, $d['length']);
        if ($data === false || strlen($data) < 4) {
            throw new RuntimeException('Nelze načíst metadata');
        }
        
        $len = unpack('N', substr($data, 0, 4))[1];
        $json = substr($data, 4, $len);
        $decoded = json_decode($json, true);
        
        if (!is_array($decoded) || ($decoded['magic'] ?? '') !== self::MAGIC) {
            throw new RuntimeException('Neplatná metadata');
        }
        
        $this->header = $decoded;
        $this->freeRowids = $this->header['freeRowids'] ?? [];
    }

    // ======================================================================
    // ZÓNA A - S RECYKLACÍ (nejprve volné rowidy, pak append)
    // ======================================================================

    private function getMaxRowid(): int
    {
        fseek($this->fh, 0, SEEK_END);
        $size = ftell($this->fh);
        if ($size <= 0) return 1;
        return intdiv($size, self::ZONE_A_ROW_LEN);
    }

    private function loadFreeRowids(): void
    {
        $this->freeRowids = $this->header['freeRowids'] ?? [];
        
        // Ověříme, že rowidy jsou skutečně volné
        $this->freeRowids = array_values(array_filter($this->freeRowids, function($rowid) {
            $entry = $this->readZoneA($rowid);
            return $entry === null || $entry['type'] === self::TYPE_FREE;
        }));
    }

    private function allocRowid(): int
    {
        // 1. Zkusíme použít volný rowid
        if (!empty($this->freeRowids)) {
            $rowid = array_pop($this->freeRowids);
            $this->header['freeRowids'] = $this->freeRowids;
            return $rowid;
        }
        
        // 2. Jinak append
        return $this->nextRowid++;
    }

    private function freeRowid(int $rowid): void
    {
        if ($rowid === 0) return; // Nikdy neuvolňujeme rowid 0
        $this->freeRowids[] = $rowid;
        $this->header['freeRowids'] = $this->freeRowids;
    }

    private function zoneAPos(int $rowid): int
    {
        return $rowid * self::ZONE_A_ROW_LEN;
    }

    private function writeZoneA(int $rowid, int $offset, int $length, int $type): void
    {
        $pos = $this->zoneAPos($rowid);
        fseek($this->fh, $pos);
        fwrite($this->fh, pack('N3', $offset, $length, $type));
        
        if ($rowid >= $this->nextRowid) {
            $this->nextRowid = $rowid + 1;
        }
    }

    private function readZoneA(int $rowid): ?array
    {
        $pos = $this->zoneAPos($rowid);
        fseek($this->fh, $pos);
        $raw = fread($this->fh, self::ZONE_A_ROW_LEN);
        if ($raw === false || strlen($raw) < self::ZONE_A_ROW_LEN) return null;
        $d = unpack('Noffset/Nlength/Ntype', $raw);
        if ($d['type'] === self::TYPE_FREE) return null;
        return $d;
    }

    // ======================================================================
    // ZÓNA B - DATA S RECYKLACÍ DĚR
    // ======================================================================

    private function getHoles(string $table): array
    {
        if ($table === '_system' || $table === '_cache') {
            return $this->header['_holes'] ?? [];
        }
        return $this->header['tables'][$table]['holes'] ?? [];
    }

    private function setHoles(string $table, array $holes): void
    {
        if ($table === '_system' || $table === '_cache') {
            $this->header['_holes'] = $holes;
        } else {
            $this->header['tables'][$table]['holes'] = $holes;
        }
    }

    private function findHole(string $table, int $needed): ?int
    {
        $holes = $this->getHoles($table);
        $bestIdx = null;
        $bestSize = PHP_INT_MAX;

        foreach ($holes as $i => $h) {
            if ($h['length'] >= $needed && $h['length'] < $bestSize) {
                $bestSize = $h['length'];
                $bestIdx = $i;
            }
        }

        if ($bestIdx !== null) {
            $hole = $holes[$bestIdx];
            unset($holes[$bestIdx]);
            $holes = array_values($holes);
            $this->setHoles($table, $holes);
            return $hole['offset'];
        }

        return null;
    }

    private function addHole(string $table, int $offset, int $length): void
    {
        if ($length <= 0) return;

        $holes = $this->getHoles($table);
        $holes[] = ['offset' => $offset, 'length' => $length];

        if (count($holes) > self::HOLE_LIMIT) {
            $holes = array_values(array_filter($holes, fn($h) => $h['length'] >= self::MICRO_HOLE));
        }

        // Sloučení sousedních děr
        usort($holes, fn($a, $b) => $a['offset'] <=> $b['offset']);
        $merged = [];
        foreach ($holes as $h) {
            if (empty($merged)) {
                $merged[] = $h;
                continue;
            }
            $last = &$merged[array_key_last($merged)];
            if ($last['offset'] + $last['length'] >= $h['offset']) {
                $last['length'] = max($last['length'], $h['offset'] + $h['length'] - $last['offset']);
            } else {
                $merged[] = $h;
            }
        }
        
        $this->setHoles($table, $merged);
    }

    private function writeData(string $table, string $data): int
    {
        $needed = strlen($data);
        $offset = $this->findHole($table, $needed);

        if ($offset !== null) {
            fseek($this->fh, $offset);
            fwrite($this->fh, $data);
            
            // Zbytek díry
            $holeEnd = $offset + $needed;
            fseek($this->fh, 0, SEEK_END);
            $fileEnd = ftell($this->fh);
            
            if ($holeEnd < $fileEnd) {
                $remaining = $fileEnd - $holeEnd;
                $this->addHole($table, $holeEnd, $remaining);
            }
            return $offset;
        }

        // Append na konec
        fseek($this->fh, 0, SEEK_END);
        $offset = ftell($this->fh);
        fwrite($this->fh, $data);
        return $offset;
    }

    private function readBlock(int $offset, int $length): string
    {
        fseek($this->fh, $offset);
        $data = fread($this->fh, $length);
        return $data === false ? '' : $data;
    }

    // ======================================================================
    // ZÁMKY
    // ======================================================================

    private function lockWrite(callable $fn)
    {
        if (!flock($this->fh, LOCK_EX)) {
            throw new RuntimeException('Nepodařilo se získat exkluzivní zámek');
        }
        try {
            $result = $fn();
            fflush($this->fh);
            return $result;
        } finally {
            flock($this->fh, LOCK_UN);
        }
    }

    private function lockRead(callable $fn)
    {
        flock($this->fh, LOCK_SH);
        try {
            return $fn();
        } finally {
            flock($this->fh, LOCK_UN);
        }
    }

    // ======================================================================
    // SERIALIZACE
    // ======================================================================

    private const V_NULL = 0;
    private const V_INT = 1;
    private const V_FLOAT = 2;
    private const V_STRING = 3;
    private const V_BLOB = 4;

    private function blobPath(string $hash): string
    {
        $dir = dirname($this->path) . '/blobs/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        return $dir . '/' . $hash . '.txt';
    }

    private function encodeValue($val): string
    {
        if ($val === null) return chr(self::V_NULL);
        if (is_int($val)) return chr(self::V_INT) . pack('J', $val);
        if (is_float($val)) return chr(self::V_FLOAT) . pack('E', $val);

        $str = (string)$val;
        $len = strlen($str);

        if ($this->header['blobMode'] && $len > self::BLOB_THRESHOLD) {
            $hash = md5($str);
            $path = $this->blobPath($hash);
            $dir = dirname($path);
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            if (!file_exists($path)) file_put_contents($path, $str, LOCK_EX);
            return chr(self::V_BLOB) . pack('N', 32) . $hash;
        }

        return chr(self::V_STRING) . pack('N', $len) . $str;
    }

    private function encodeRow(array $columns, array $row): string
    {
        $out = pack('n', count($columns));
        foreach ($columns as $col) {
            $out .= $this->encodeValue($row[$col] ?? null);
        }
        return $out;
    }

    private function decodeRow(string $bin, array $columns): array
    {
        $pos = 0;
        $count = unpack('n', substr($bin, $pos, 2))[1];
        $pos += 2;
        $row = [];

        for ($i = 0; $i < $count; $i++) {
            $col = $columns[$i] ?? "col{$i}";
            $type = ord($bin[$pos++]);

            switch ($type) {
                case self::V_NULL: $row[$col] = null; break;
                case self::V_INT: 
                    $row[$col] = unpack('J', substr($bin, $pos, 8))[1]; 
                    $pos += 8; 
                    break;
                case self::V_FLOAT:
                    $row[$col] = unpack('E', substr($bin, $pos, 8))[1];
                    $pos += 8;
                    break;
                case self::V_STRING:
                    $len = unpack('N', substr($bin, $pos, 4))[1];
                    $pos += 4;
                    $row[$col] = substr($bin, $pos, $len);
                    $pos += $len;
                    break;
                case self::V_BLOB:
                    $len = unpack('N', substr($bin, $pos, 4))[1];
                    $pos += 4;
                    $hash = substr($bin, $pos, $len);
                    $pos += $len;
                    $path = $this->blobPath($hash);
                    $row[$col] = is_file($path) ? file_get_contents($path) : null;
                    break;
                default:
                    throw new RuntimeException("Neznámý typ: {$type}");
            }
        }
        return $row;
    }

    // ======================================================================
    // INDEXY
    // ======================================================================

    private function indexInsert(string $table, string $col, $key, int $rowid): void
    {
        $this->memoryIndex[$table][$col][(string)$key][] = $rowid;
    }

    private function indexSearch(string $table, string $col, $key): array
    {
        return $this->memoryIndex[$table][$col][(string)$key] ?? [];
    }

    private function indexDelete(string $table, string $col, $key, int $rowid): void
    {
        $keyStr = (string)$key;
        if (isset($this->memoryIndex[$table][$col][$keyStr])) {
            $this->memoryIndex[$table][$col][$keyStr] = array_values(
                array_filter($this->memoryIndex[$table][$col][$keyStr], fn($r) => $r !== $rowid)
            );
            if (empty($this->memoryIndex[$table][$col][$keyStr])) {
                unset($this->memoryIndex[$table][$col][$keyStr]);
            }
        }
    }

    private function indexLike(string $table, string $col, string $prefix): array
    {
        $results = [];
        if (!isset($this->memoryIndex[$table][$col])) return [];
        foreach ($this->memoryIndex[$table][$col] as $key => $rowids) {
            if (str_starts_with($key, $prefix)) {
                $results = array_merge($results, $rowids);
            }
        }
        return $results;
    }

    // ======================================================================
    // PERZISTENTNÍ CACHE
    // ======================================================================

    private function loadCacheFromDisk(): void
    {
        $this->memoryCache = [];
        
        if (empty($this->header['cache'])) {
            return;
        }

        foreach ($this->header['cache'] as $key => $rowid) {
            $entry = $this->readZoneA($rowid);
            if ($entry === null || $entry['type'] !== self::TYPE_CACHE) {
                continue;
            }
            
            $data = $this->readBlock($entry['offset'], $entry['length']);
            if ($data === '') continue;
            
            $timestamp = unpack('N', substr($data, 0, 4))[1];
            $value = unserialize(substr($data, 4));
            
            if (time() - $timestamp < self::CACHE_TTL) {
                $this->memoryCache[$key] = $value;
            } else {
                $this->deleteCacheItem($rowid);
            }
        }
    }

    private function saveCacheItem(string $key, $value): void
    {
        // 1. Serilizace
        $data = pack('N', time()) . serialize($value);
        
        // 2. Zápis do Zóny B
        $offset = $this->writeData('_cache', $data);
        $length = strlen($data);
        
        // 3. Alokace rowid (s recyklací)
        $rowid = $this->allocRowid();
        $this->writeZoneA($rowid, $offset, $length, self::TYPE_CACHE);
        
        // 4. Uložení do metadat
        $this->header['cache'][$key] = $rowid;
        
        // 5. RAM cache
        $this->memoryCache[$key] = $value;
        
        // 6. Uložení hlavičky
        $this->writeHeaderData();
    }

    private function deleteCacheItem(int $rowid): void
    {
        $entry = $this->readZoneA($rowid);
        if ($entry !== null) {
            $this->addHole('_cache', $entry['offset'], $entry['length']);
        }
        $this->writeZoneA($rowid, 0, 0, self::TYPE_FREE);
        $this->freeRowid($rowid);
    }

    private function getCacheItem(string $key)
    {
        if (isset($this->memoryCache[$key])) {
            return $this->memoryCache[$key];
        }

        if (isset($this->header['cache'][$key])) {
            $rowid = $this->header['cache'][$key];
            $entry = $this->readZoneA($rowid);
            
            if ($entry !== null && $entry['type'] === self::TYPE_CACHE) {
                $data = $this->readBlock($entry['offset'], $entry['length']);
                if ($data !== '') {
                    $timestamp = unpack('N', substr($data, 0, 4))[1];
                    
                    if (time() - $timestamp < self::CACHE_TTL) {
                        $value = unserialize(substr($data, 4));
                        $this->memoryCache[$key] = $value;
                        return $value;
                    } else {
                        $this->deleteCacheItem($rowid);
                        unset($this->header['cache'][$key]);
                        $this->writeHeaderData();
                    }
                }
            }
        }

        return null;
    }

    private function invalidateCache(): void
    {
        foreach ($this->header['cache'] as $key => $rowid) {
            $this->deleteCacheItem($rowid);
        }
        
        $this->header['cache'] = [];
        $this->memoryCache = [];
        $this->writeHeaderData();
    }

    // ======================================================================
    // CRUD OPERACE
    // ======================================================================

    public function createTable(string $name, array $columns, array $indexes = []): void
    {
        if (isset($this->header['tables'][$name])) {
            throw new RuntimeException("Tabulka '{$name}' již existuje");
        }

        $this->lockWrite(function () use ($name, $columns, $indexes) {
            $this->header['tables'][$name] = [
                'columns' => array_values($columns),
                'indexes' => $indexes,
                'holes' => [],
                'changeLog' => [],
                'cacheDisabled' => false,
            ];
            $this->writeHeaderData();
        });
    }

    public function insert(string $table, array $row): int
    {
        $this->assertTable($table);

        return $this->lockWrite(function () use ($table, $row) {
            $def = $this->header['tables'][$table];
            $bin = $this->encodeRow($def['columns'], $row);
            $offset = $this->writeData($table, $bin);
            $rowid = $this->allocRowid();
            $this->writeZoneA($rowid, $offset, strlen($bin), self::TYPE_DATA);

            foreach ($def['indexes'] as $col => $type) {
                if (isset($row[$col])) {
                    $this->indexInsert($table, $col, $row[$col], $rowid);
                }
            }

            $this->registerChange($table);
            $this->writeHeaderData();
            return $rowid;
        });
    }

    public function select(string $table, array $columns, array $where = [], ?string $orderBy = null, string $orderDir = 'ASC', ?int $limit = null): array
    {
        $this->assertTable($table);

        return $this->lockRead(function () use ($table, $columns, $where, $orderBy, $orderDir, $limit) {
            $rowids = $this->findCandidates($table, $where);
            $rows = [];

            foreach ($rowids as $rowid) {
                $row = $this->fetchRow($table, $rowid);
                if ($row === null || !$this->matchesWhere($row, $where)) continue;

                if ($columns !== ['*']) {
                    $row = array_intersect_key($row, array_flip($columns));
                }
                $rows[] = $row;
            }

            if ($orderBy !== null) {
                usort($rows, function ($a, $b) use ($orderBy, $orderDir) {
                    $cmp = ($a[$orderBy] ?? null) <=> ($b[$orderBy] ?? null);
                    return $orderDir === 'DESC' ? -$cmp : $cmp;
                });
            }

            return $limit !== null ? array_slice($rows, 0, $limit) : $rows;
        });
    }

    public function update(string $table, array $set, array $where = []): int
    {
        $this->assertTable($table);

        return $this->lockWrite(function () use ($table, $set, $where) {
            $def = $this->header['tables'][$table];
            $rowids = $this->findCandidates($table, $where);
            $updated = 0;

            foreach ($rowids as $rowid) {
                $old = $this->fetchRow($table, $rowid);
                if ($old === null || !$this->matchesWhere($old, $where)) continue;

                $new = array_merge($old, $set);

                foreach ($def['indexes'] as $col => $type) {
                    if (isset($set[$col]) && isset($old[$col])) {
                        $this->indexDelete($table, $col, $old[$col], $rowid);
                    }
                }

                $entry = $this->readZoneA($rowid);
                if ($entry !== null) {
                    $this->addHole($table, $entry['offset'], $entry['length']);
                }

                $bin = $this->encodeRow($def['columns'], $new);
                $offset = $this->writeData($table, $bin);
                $this->writeZoneA($rowid, $offset, strlen($bin), self::TYPE_DATA);

                foreach ($def['indexes'] as $col => $type) {
                    if (isset($new[$col])) {
                        $this->indexInsert($table, $col, $new[$col], $rowid);
                    }
                }

                $updated++;
            }

            if ($updated > 0) {
                $this->registerChange($table);
                $this->writeHeaderData();
            }

            return $updated;
        });
    }

    public function delete(string $table, array $where = []): int
    {
        $this->assertTable($table);

        return $this->lockWrite(function () use ($table, $where) {
            $def = $this->header['tables'][$table];
            $rowids = $this->findCandidates($table, $where);
            $deleted = 0;

            foreach ($rowids as $rowid) {
                $row = $this->fetchRow($table, $rowid);
                if ($row === null || !$this->matchesWhere($row, $where)) continue;

                foreach ($def['indexes'] as $col => $type) {
                    if (isset($row[$col])) {
                        $this->indexDelete($table, $col, $row[$col], $rowid);
                    }
                }

                $entry = $this->readZoneA($rowid);
                if ($entry !== null) {
                    $this->addHole($table, $entry['offset'], $entry['length']);
                }
                $this->writeZoneA($rowid, 0, 0, self::TYPE_FREE);
                $this->freeRowid($rowid);
                $deleted++;
            }

            if ($deleted > 0) {
                $this->registerChange($table);
                $this->writeHeaderData();
            }

            return $deleted;
        });
    }

    public function upsert(string $table, array $row, string $keyCol): array
    {
        $this->assertTable($table);
        if (!isset($row[$keyCol])) {
            throw new RuntimeException("UPSERT vyžaduje klíčový sloupec '{$keyCol}'");
        }

        $where = [['col' => $keyCol, 'op' => '=', 'val' => $row[$keyCol]]];
        $existing = $this->select($table, ['*'], $where, null, 'ASC', 1);

        if (!empty($existing)) {
            $affected = $this->update($table, $row, $where);
            return ['created' => false, 'affected' => $affected];
        }

        $rowid = $this->insert($table, $row);
        return ['created' => true, 'rowid' => $rowid];
    }

    public function optimize(string $table): void
    {
        $this->assertTable($table);
        $this->lockWrite(function () use ($table) {
            $this->header['tables'][$table]['holes'] = [];
            $this->writeHeaderData();
        });
    }

    // ======================================================================
    // HELPERS
    // ======================================================================

    private function assertTable(string $name): void
    {
        if (!isset($this->header['tables'][$name])) {
            throw new RuntimeException("Tabulka '{$name}' neexistuje");
        }
    }

    private function fetchRow(string $table, int $rowid): ?array
    {
        $entry = $this->readZoneA($rowid);
        if ($entry === null || $entry['type'] !== self::TYPE_DATA) return null;
        $def = $this->header['tables'][$table];
        return $this->decodeRow($this->readBlock($entry['offset'], $entry['length']), $def['columns']);
    }

    private function findCandidates(string $table, array $where): array
    {
        $def = $this->header['tables'][$table];

        foreach ($where as $cond) {
            $col = $cond['col'];
            $op = $cond['op'];
            $val = $cond['val'];

            if (!isset($def['indexes'][$col])) continue;

            if ($op === '=') {
                return $this->indexSearch($table, $col, $val);
            }
            if ($op === 'LIKE_PREFIX') {
                return $this->indexLike($table, $col, (string)$val);
            }
        }

        // Full scan - procházíme všechny rowidy kromě 0
        $rowids = [];
        for ($rowid = 1; $rowid < $this->nextRowid; $rowid++) {
            // Přeskočíme volné rowidy
            if (in_array($rowid, $this->freeRowids)) continue;
            
            $entry = $this->readZoneA($rowid);
            if ($entry !== null && $entry['type'] === self::TYPE_DATA) {
                $rowids[] = $rowid;
            }
        }
        return $rowids;
    }

    private function matchesWhere(array $row, array $where): bool
    {
        foreach ($where as $cond) {
            $v = $row[$cond['col']] ?? null;
            switch ($cond['op']) {
                case '=': if ($v != $cond['val']) return false; break;
                case '!=': if ($v == $cond['val']) return false; break;
                case '>': if (!($v > $cond['val'])) return false; break;
                case '>=': if (!($v >= $cond['val'])) return false; break;
                case '<': if (!($v < $cond['val'])) return false; break;
                case '<=': if (!($v <= $cond['val'])) return false; break;
                case 'LIKE_PREFIX': if (!str_starts_with((string)$v, (string)$cond['val'])) return false; break;
                case 'BETWEEN': if (!($v >= $cond['val'][0] && $v <= $cond['val'][1])) return false; break;
            }
        }
        return true;
    }

    // ======================================================================
    // CACHE MANAGEMENT
    // ======================================================================

    private function registerChange(string $table): void
    {
        $now = time();
        $def = &$this->header['tables'][$table];
        
        $def['changeLog'][] = $now;
        $def['changeLog'] = array_values(array_filter(
            $def['changeLog'],
            fn($t) => $t >= $now - self::CACHE_RESET_HOURS * 3600
        ));

        if (count($def['changeLog']) > self::CACHE_MAX_CHANGES) {
            $def['cacheDisabled'] = true;
            $this->invalidateCache();
        } else {
            $def['cacheDisabled'] = false;
        }
    }

    // ======================================================================
    // SQL PARSER
    // ======================================================================

    public function query(string $sql): array
    {
        $start = microtime(true);
        $sql = trim($sql);

        try {
            $result = $this->parseSql($sql);
            $result['success'] = true;
        } catch (Throwable $e) {
            $result = ['success' => false, 'error' => $e->getMessage()];
        }

        $result['execution_time_ms'] = round((microtime(true) - $start) * 1000, 4);
        return $result;
    }

    private function parseSql(string $sql): array
    {
        // CREATE TABLE
        if (preg_match('/^CREATE\s+TABLE\s+`?(\w+)`?\s*\((.+)\)$/is', $sql, $m)) {
            $columns = [];
            $indexes = [];
            foreach (explode(',', $m[2]) as $part) {
                $part = trim($part);
                if (preg_match('/^(\w+)\s+(\w+)(?:\s+INDEX)?$/i', $part, $cm)) {
                    $columns[] = $cm[1];
                    if (strpos($part, 'INDEX') !== false) {
                        $indexes[] = $cm[1];
                    }
                } elseif (preg_match('/^(\w+)\s+(\w+)$/i', $part, $cm)) {
                    $columns[] = $cm[1];
                }
            }
            $this->createTable($m[1], $columns, array_flip($indexes));
            return ['action' => 'CREATE_TABLE', 'table' => $m[1]];
        }

        // INSERT
        if (preg_match('/^INSERT\s+INTO\s+`?(\w+)`?\s*\(([^)]+)\)\s*VALUES\s*\((.+)\)$/is', $sql, $m)) {
            $table = $m[1];
            $cols = array_map('trim', explode(',', $m[2]));
            $vals = $this->splitValues($m[3]);
            if (count($cols) !== count($vals)) {
                throw new RuntimeException('Počet sloupců a hodnot se neshoduje');
            }
            $row = array_combine($cols, array_map([$this, 'parseValue'], $vals));
            $rowid = $this->insert($table, $row);
            return ['action' => 'INSERT', 'table' => $table, 'rowid' => $rowid];
        }

        // SELECT
        if (preg_match('/^SELECT\s+(.+?)\s+FROM\s+`?(\w+)`?(?:\s+WHERE\s+(.+?))?(?:\s+ORDER\s+BY\s+(\w+)(?:\s+(ASC|DESC))?)?(?:\s+LIMIT\s+(\d+))?$/is', $sql, $m)) {
            $table = $m[2];
            $this->assertTable($table);

            $def = $this->header['tables'][$table];
            
            if (!$def['cacheDisabled']) {
                $cacheKey = md5($sql);
                $cached = $this->getCacheItem($cacheKey);
                if ($cached !== null) {
                    return ['action' => 'SELECT', 'table' => $table, 'rows' => $cached, 'cache_hit' => true];
                }
            }

            $columns = trim($m[1]) === '*' ? ['*'] : array_map('trim', explode(',', $m[1]));
            $where = !empty($m[3]) ? $this->parseWhere($m[3]) : [];
            $orderBy = $m[4] ?? null;
            $orderDir = strtoupper($m[5] ?? 'ASC');
            $limit = isset($m[6]) ? (int)$m[6] : null;

            $rows = $this->select($table, $columns, $where, $orderBy, $orderDir, $limit);
            
            if (!$def['cacheDisabled']) {
                $cacheKey = md5($sql);
                $this->saveCacheItem($cacheKey, $rows);
            }
            
            return ['action' => 'SELECT', 'table' => $table, 'rows' => $rows, 'cache_hit' => false];
        }

        // UPDATE
        if (preg_match('/^UPDATE\s+`?(\w+)`?\s+SET\s+(.+?)(?:\s+WHERE\s+(.+))?$/is', $sql, $m)) {
            $table = $m[1];
            $set = [];
            foreach (explode(',', $m[2]) as $assign) {
                if (preg_match('/^\s*(\w+)\s*=\s*(.+?)\s*$/', $assign, $am)) {
                    $set[$am[1]] = $this->parseValue(trim($am[2]));
                }
            }
            $where = isset($m[3]) ? $this->parseWhere($m[3]) : [];
            $affected = $this->update($table, $set, $where);
            return ['action' => 'UPDATE', 'table' => $table, 'affected' => $affected];
        }

        // DELETE
        if (preg_match('/^DELETE\s+FROM\s+`?(\w+)`?(?:\s+WHERE\s+(.+))?$/is', $sql, $m)) {
            $table = $m[1];
            $where = isset($m[2]) ? $this->parseWhere($m[2]) : [];
            $affected = $this->delete($table, $where);
            return ['action' => 'DELETE', 'table' => $table, 'affected' => $affected];
        }

        // UPSERT
        if (preg_match('/^UPSERT\s+INTO\s+`?(\w+)`?\s*\(([^)]+)\)\s*VALUES\s*\((.+)\)(?:\s+KEY\s*\((\w+)\))?$/is', $sql, $m)) {
            $table = $m[1];
            $cols = array_map('trim', explode(',', $m[2]));
            $vals = $this->splitValues($m[3]);
            if (count($cols) !== count($vals)) {
                throw new RuntimeException('Počet sloupců a hodnot se neshoduje');
            }
            $row = array_combine($cols, array_map([$this, 'parseValue'], $vals));
            $keyCol = $m[4] ?? $cols[0];
            $result = $this->upsert($table, $row, $keyCol);
            return ['action' => 'UPSERT', 'table' => $table, 'keyCol' => $keyCol] + $result;
        }

        // OPTIMIZE
        if (preg_match('/^OPTIMIZE\s+TABLE\s+`?(\w+)`?$/i', $sql, $m)) {
            $this->optimize($m[1]);
            return ['action' => 'OPTIMIZE', 'table' => $m[1]];
        }

        throw new RuntimeException('Nepodporovaný SQL příkaz: ' . $sql);
    }

    private function splitValues(string $raw): array
    {
        $values = [];
        $buf = '';
        $inStr = false;
        $len = strlen($raw);

        for ($i = 0; $i < $len; $i++) {
            $ch = $raw[$i];
            if ($ch === "'" && ($i === 0 || $raw[$i - 1] !== '\\')) {
                $inStr = !$inStr;
                $buf .= $ch;
                continue;
            }
            if ($ch === ',' && !$inStr) {
                $values[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') $values[] = trim($buf);
        return $values;
    }

    private function parseValue(string $v)
    {
        $v = trim($v);
        if (strtoupper($v) === 'NULL') return null;
        if (preg_match('/^\'(.*)\'$/s', $v, $m)) {
            return str_replace("\\'", "'", $m[1]);
        }
        if (preg_match('/^-?\d+$/', $v)) return (int)$v;
        if (preg_match('/^-?\d+\.\d+$/', $v)) return (float)$v;
        return $v;
    }

    private function parseWhere(string $raw): array
    {
        $conditions = [];
        $between = [];

        $raw = preg_replace_callback(
            '/(\w+)\s+BETWEEN\s+([^\s]+)\s+AND\s+([^\s]+)/i',
            function ($m) use (&$between) {
                $token = '__BETWEEN_' . count($between) . '__';
                $between[$token] = [
                    'col' => $m[1],
                    'op' => 'BETWEEN',
                    'val' => [$this->parseValue($m[2]), $this->parseValue($m[3])]
                ];
                return $token;
            },
            $raw
        );

        foreach (preg_split('/\s+AND\s+/i', $raw) as $part) {
            $part = trim($part);
            if (isset($between[$part])) {
                $conditions[] = $between[$part];
                continue;
            }
            if (preg_match('/^(\w+)\s+LIKE\s+\'([^%]*)%\'$/i', $part, $m)) {
                $conditions[] = ['col' => $m[1], 'op' => 'LIKE_PREFIX', 'val' => $m[2]];
                continue;
            }
            if (preg_match('/^(\w+)\s*(>=|<=|!=|=|>|<)\s*(.+)$/', $part, $m)) {
                $conditions[] = ['col' => $m[1], 'op' => $m[2], 'val' => $this->parseValue($m[3])];
            }
        }
        return $conditions;
    }

    // ======================================================================
    // STATISTIKA
    // ======================================================================

    public function stats(): array
    {
        $stats = [
            'diskType' => $this->header['diskType'],
            'pageSize' => $this->header['pageSize'],
            'blobMode' => $this->header['blobMode'],
            'nextRowid' => $this->nextRowid,
            'freeRowids' => count($this->freeRowids),
            'headerRowid' => 0,
            'fileSize' => filesize($this->path),
            'cacheItems' => count($this->header['cache'] ?? []),
            'cacheDisabled' => $this->header['cacheDisabled'] ?? false,
            'tables' => [],
        ];

        foreach ($this->header['tables'] as $name => $def) {
            $rows = 0;
            for ($rowid = 1; $rowid < $this->nextRowid; $rowid++) {
                if (in_array($rowid, $this->freeRowids)) continue;
                $entry = $this->readZoneA($rowid);
                if ($entry !== null && $entry['type'] === self::TYPE_DATA) {
                    $rows++;
                }
            }
            $stats['tables'][$name] = [
                'rows' => $rows,
                'holes' => count($def['holes']),
                'holeBytes' => array_sum(array_column($def['holes'], 'length')),
                'cacheDisabled' => $def['cacheDisabled'] ?? false,
                'changes24h' => count($def['changeLog'] ?? []),
            ];
        }

        return $stats;
    }
}

//// ======================================================================
//// KOMPLEXNÍ TEST
//// ======================================================================

//echo "=== KOMPLEXNÍ TEST - VŠECHNY ZÓNY S RECYKLACÍ ===\n\n";

//$dbFile = __DIR__ . '/test_complete.db';
//@unlink($dbFile);

//$db = new GraphDbComplete($dbFile);

//// 1. CREATE TABLE
//echo "1. CREATE TABLE\n";
//$db->query("CREATE TABLE users (id INT, name TEXT INDEX, age INT INDEX, bio TEXT)");
//$db->query("CREATE TABLE products (id INT, name TEXT INDEX, price INT)");

//// 2. INSERT
//echo "\n2. INSERT 500 users a 100 products\n";
//for ($i = 1; $i <= 500; $i++) {
    //$db->query("INSERT INTO users (id, name, age, bio) VALUES ($i, 'User$i', " . rand(18, 80) . ", 'Bio $i')");
//}
//for ($i = 1; $i <= 100; $i++) {
    //$db->query("INSERT INTO products (id, name, price) VALUES ($i, 'Product$i', " . rand(100, 1000) . ")");
//}

//$stats = $db->stats();
//echo "Rowidů: " . $stats['nextRowid'] . "\n";
//echo "Volných rowidů: " . $stats['freeRowids'] . "\n";
//echo "Velikost souboru: " . $stats['fileSize'] . " B\n";

//// 3. DELETE (vytvoří díry v Zóně A i B)
//echo "\n3. DELETE - vytvoření děr\n";
//for ($i = 10; $i <= 30; $i++) {
    //$db->query("DELETE FROM users WHERE id = $i");
//}
//for ($i = 20; $i <= 30; $i++) {
    //$db->query("DELETE FROM products WHERE id = $i");
//}

//$stats = $db->stats();
//echo "Rowidů: " . $stats['nextRowid'] . "\n";
//echo "Volných rowidů: " . $stats['freeRowids'] . "\n";
//echo "Děry v users: " . $stats['tables']['users']['holes'] . "\n";
//echo "Děry v products: " . $stats['tables']['products']['holes'] . "\n";

//// 4. INSERT - využije díry (recyklace v Zóně A i B)
//echo "\n4. INSERT - recyklace děr\n";
//for ($i = 1; $i <= 30; $i++) {
    //$db->query("INSERT INTO users (id, name, age, bio) VALUES (" . (500 + $i) . ", 'NewUser$i', " . rand(18, 80) . ", 'New bio $i')");
//}

//$stats = $db->stats();
//echo "Rowidů: " . $stats['nextRowid'] . "\n";
//echo "Volných rowidů: " . $stats['freeRowids'] . "\n";
//echo "Děry v users: " . $stats['tables']['users']['holes'] . "\n";

//// 5. SELECT a CACHE
//echo "\n5. SELECT a perzistentní cache\n";
//$start = microtime(true);
//$res1 = $db->query("SELECT * FROM users WHERE name = 'User50'");
//$time1 = microtime(true) - $start;
//echo "1. SELECT (bez cache): " . count($res1['rows']) . " výsledků, " . number_format($time1 * 1000, 2) . "ms\n";

//$start = microtime(true);
//$res2 = $db->query("SELECT * FROM users WHERE name = 'User50'");
//$time2 = microtime(true) - $start;
//echo "2. SELECT (cache): " . count($res2['rows']) . " výsledků, " . number_format($time2 * 1000, 2) . "ms, hit=" . var_export($res2['cache_hit'] ?? false, true) . "\n";

//// 6. UPDATE - invalidace cache
//echo "\n6. UPDATE - invalidace cache\n";
//$db->query("UPDATE users SET age = 99 WHERE name = 'User50'");

//$start = microtime(true);
//$res3 = $db->query("SELECT * FROM users WHERE name = 'User50'");
//$time3 = microtime(true) - $start;
//echo "3. SELECT (po UPDATE): " . count($res3['rows']) . " výsledků, " . number_format($time3 * 1000, 2) . "ms, hit=" . var_export($res3['cache_hit'] ?? false, true) . "\n";

//// 7. Restart a načtení cache z disku
//echo "\n7. RESTART - načtení cache z disku\n";
//$db->__destruct();

//$db2 = new GraphDbComplete($dbFile);
//$start = microtime(true);
//$res4 = $db2->query("SELECT * FROM users WHERE name = 'User100'");
//$time4 = microtime(true) - $start;
//echo "4. SELECT (po restartu): " . count($res4['rows']) . " výsledků, " . number_format($time4 * 1000, 2) . "ms, hit=" . var_export($res4['cache_hit'] ?? false, true) . "\n";

//// 8. UPSERT
//echo "\n8. UPSERT\n";
//$res = $db2->query("UPSERT INTO users (id, name, age, bio) VALUES (999, 'UpsertUser', 30, 'Upsert bio') KEY (id)");
//echo "UPSERT: " . ($res['created'] ? 'vytvořen' : 'aktualizován') . "\n";

//// 9. OPTIMIZE
//echo "\n9. OPTIMIZE TABLE\n";
//$db2->query("OPTIMIZE TABLE users");
//$stats = $db2->stats();
//echo "Děry po OPTIMIZE: " . $stats['tables']['users']['holes'] . "\n";

//// 10. FINÁLNÍ STATISTIKA
//echo "\n=== FINÁLNÍ STATISTIKA ===\n";
//print_r($db2->stats());

//@unlink($dbFile);
//echo "\nTest proběhl úspěšně!\n";
