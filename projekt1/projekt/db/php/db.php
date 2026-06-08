
<?php

class DB {
    private static ?self $instance = null;
    
    // Konfigurace fixních bajtových délek pro matematický fseek()
    private int $velikostCentralnihoRadku = 100;  // 100 bajtů v .rowidx
    private int $velikostSekundarnihoRadku = 48; // 48 bajtů v .idx (16B hash + 31B text ID + \n)
    private int $maxVelikostSegmentu = 2097152;   // 2 MB na datový soubor .db
    private int $maxVelikostLogu = 524288;        // 512 KB na soubor centrálního logu
    private int $limitLoguProCisteni = 5000;      // Po 5000 zápisech v logu spustit GC

    // Vnitřní paměťové registry pro RAM cache
    private array $mapovaniPozicCache = [];
    private bool $vnitrniBlokaceAnalizy = false;
    private int $queryCount = 0;

    public static function getInstance(): self {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function ziskejAktualnihoUzivatele(): int {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        return (int)($_SESSION['user_id'] ?? 0);
    }

    private function bezpecnyLock(SplFileObject $soubor, int $typZamku): bool {
        for ($i = 0; $i < 10; $i++) {
            if ($soubor->flock($typZamku | LOCK_NB)) return true;
            usleep(3000); // 3 ms pauza při obsazení jinou relací
        }
        return false;
    }

    private function kontrolaAGlobalniLock(string $akce): bool {
        $cestaZamku = DB_PATH . "/db_maintenance.lock";
        if ($akce === 'check') return file_exists($cestaZamku) && (time() - filemtime($cestaZamku) < 300);
        if ($akce === 'lock') return (bool)file_put_contents($cestaZamku, time(), LOCK_EX);
        if ($akce === 'unlock') { if (file_exists($cestaZamku)) @unlink($cestaZamku); return true; }
        return false;
    }

    private function generujCestuKSegmentu(string $table, int $segment): string {
        return DB_PATH . "/json/{$table}_{$segment}.db";
    }

    private function nactiGlobalniGraf(SplFileObject $soubor): array {
        $obsah = ''; $soubor->rewind();
        while (!$soubor->eof()) $obsah .= $soubor->fgets();
        return json_decode(trim($obsah), true) ?? [];
    }

    /**
     * INICIALIZACE MAPY TEXTOVÝCH ID DO PAMĚTI RAM (Načítá index .rowidx z disku)
     */
    private function inicializujTabulku(string $table): void {
        if (isset($this->mapovaniPozicCache[$table])) return;
        $this->mapovaniPozicCache[$table] = [];
        
        $cestaRowIdx = DB_PATH . "/json/{$table}.rowidx";
        if (!file_exists($cestaRowIdx)) {
            if (!is_dir(dirname($cestaRowIdx))) mkdir(dirname($cestaRowIdx), 0755, true);
            touch($cestaRowIdx);
            return;
        }

        $fsRowIdx = new SplFileObject($cestaRowIdx, 'r');
        foreach ($fsRowIdx as $radek) {
            $radekTrim = trim($radek);
            if (empty($radekTrim)) continue;

            $data = json_decode($radekTrim, true);
            if ($data && isset($data['id'])) {
                $idStr = (string)$data['id'];
                
                if (isset($data['_deleted']) && $data['_deleted'] === true) {
                    unset($this->mapovaniPozicCache[$table][$idStr]);
                } else {
                    if (!isset($this->mapovaniPozicCache[$table][$idStr])) {
                        $this->mapovaniPozicCache[$table][$idStr] = [];
                    }
                    // Přidáme pozici verze (index 0 = nejnovější platný stav)
                    array_unshift($this->mapovaniPozicCache[$table][$idStr], [
                        'seg' => (int)$data['seg'],
                        'adr' => (int)$data['adr'],
                        '_row_id' => (int)($data['_row_id'] ?? 1)
                    ]);
                    
                    // Oříznutí historie v paměti na max 5 stavů
                    if (count($this->mapovaniPozicCache[$table][$idStr]) > 5) {
                        array_pop($this->mapovaniPozicCache[$table][$idStr]);
                    }
                }
            }
        }
    }

    /**
     * SYNCHRONIZACE PAMĚTI RAM NA DISK (.rowidx)
     */
    private function ulozMapaIndexuNaDisk(string $table): void {
        $cestaRowIdx = DB_PATH . "/json/{$table}.rowidx";
        $fsRowIdx = new SplFileObject($cestaRowIdx, 'c+');
        if ($this->bezpecnyLock($fsRowIdx, LOCK_EX)) {
            $fsRowIdx->ftruncate(0);
            $fsRowIdx->rewind();

            foreach ($this->mapovaniPozicCache[$table] as $id => $verze) {
                // Zapisujeme historii chronologicky od nejstarší verze po nejnovější
                foreach (array_reverse($verze) as $pozice) {
                    $fsRowIdx->fwrite(json_encode([
                        'id' => (string)$id,
                        'seg' => (int)$pozice['seg'],
                        'adr' => (int)$pozice['adr'],
                        '_row_id' => (int)$pozice['_row_id']
                    ], JSON_UNESCAPED_UNICODE) . PHP_EOL);
                }
            }
            $fsRowIdx->flock(LOCK_UN);
        }
    }

    /**
     * HLAVNÍ VEŘEJNÁ BRÁNA PRO VŠECHNY DOTAZY A OPERACE S SCHÉMATEM
     */
    public function run(string $table, string $action, array $params = []): mixed {
        $start = microtime(true);
        $result = null;

        if ($this->kontrolaAGlobalniLock('check') && $action !== 'select') {
            usleep(50000); // 50 ms pauza, pokud zrovna probíhá údržba
            if ($this->kontrolaAGlobalniLock('check')) {
                throw new Exception("Databáze je momentálně nedostupná z důvodu automatické údržby.");
            }
        }

        $this->inicializujTabulku($table);

        try {
            switch ($action) {
                case 'insert':
                case 'upsert':
                    $result = $this->provedUpsertZapis($table, $params);
                    break;
                case 'select':
                    $result = $this->provedSelect($table, $params);
                    break;
                case 'update':
                    if (empty($params['id'])) throw new Exception("DB update: Chybí identifikátor 'id'.");
                    $result = $this->provedUpsertZapis($table, $params);
                    break;
                case 'search':
                    // NOVÁ METODA: Určená výhradně pro fulltextové vyhledávání v administraci
                    $result = $this->provedFulltextSearch($table, $params);
                    break;
                case 'delete':
                    $result = $this->provedDelete($table, $params);
                    break;
                case 'schema':
                    $result = $this->runSchema($table, $params);
                    break;
                default:
                    throw new Exception("Neznámá akce v DB jádru: $action");
            }
        } catch (Exception $e) {
            Logger::error("DB Fatal Error [$table:$action]", $e);
            throw $e;
        }

        // Výpočet celkového času trvání dotazu v milisekundách
        $duration = (microtime(true) - $start) * 1000;
        
        // Spuštění samooptimalizační analýzy
        $this->analyze($table, $action, $params, $duration);

        // Zápis do centrálního transakčního logu změn (Pouze pro modifikační akce)
        if (in_array($action, ['insert', 'update', 'upsert', 'delete'], true)) {
            $idLog = $params['id'] ?? ($params['data']['id'] ?? '0');
            if (is_array($idLog)) $idLog = $idLog['id'] ?? '0';
            $this->zapisDoCentralnihoLogu($table, (string)$idLog, $action, $duration);
        }

        return $result;
    }

    /**
     * 1. UNIVERZÁLNÍ ZÁPIS (INSERT / UPSERT / UPDATE): Chrání uživatelské ID a brání bobtnání
     */
    private function provedUpsertZapis(string $table, array $params): string {
        $dataZaznamu = $params['data'] ?? $params;
        
        if (empty($dataZaznamu['id'])) {
            $dataZaznamu['id'] = uniqid('u_', true);
        }
        $idStr = (string)$dataZaznamu['id'];

        // Kontrola, zda textové ID v paměti RAM již z minula existuje
        if (isset($this->mapovaniPozicCache[$table][$idStr]) && !empty($this->mapovaniPozicCache[$table][$idStr])) {
            // Pokud existuje, vezmeme jeho trvalé, nezaměnitelné _row_id
            $rowId = (int)$this->mapovaniPozicCache[$table][$idStr][0]['_row_id'];
        } else {
            // Pokud je to zcela nový záznam, určíme maximální dosavadní _row_id + 1
            $maxRowId = 0;
            if (isset($this->mapovaniPozicCache[$table])) {
                foreach ($this->mapovaniPozicCache[$table] as $idKlic => $verzePole) {
                    if (!empty($verzePole) && isset($verzePole[0]['_row_id'])) {
                        $maxRowId = max($maxRowId, (int)$verzePole[0]['_row_id']);
                    }
                }
            }
            $rowId = $maxRowId + 1;
        }

        // Přidání systémových meta-klíčů (Chrání vkládaná data před přepsáním)
        $dataZaznamu['_row_id'] = $rowId;
        $dataZaznamu['updated_at'] = date('Y-m-d H:i:s');
        $dataZaznamu['_zmeneno'] = time();
        $dataZaznamu['_autor_zmeny'] = $this->ziskejAktualnihoUzivatele();
        if (!isset($dataZaznamu['created_at'])) $dataZaznamu['created_at'] = date('Y-m-d H:i:s');

        // Spuštění atomizace (Pouze pokud sloupce podléhají schématu)
        $dataZaznamu = $this->atomizujDataNaVyzadani($table, $dataZaznamu);

        // Nalezení nebo založení volného 2MB datového segmentu
        $segment = 1;
        while (true) {
            $cestaDat = $this->generujCestuKSegmentu($table, $segment);
            if (file_exists($cestaDat) && filesize($cestaDat) >= $this->maxVelikostSegmentu) { $segment++; continue; }
            break;
        }

        // Zápis řádku na konec .db souboru (Append-Only)
        $fsData = new SplFileObject($this->generujCestuKSegmentu($table, $segment), 'a+');
        if ($this->bezpecnyLock($fsData, LOCK_EX)) {
            $fsData->seek(PHP_INT_MAX);
            $bajtovaAdresa = $fsData->ftell();
$fsData->fwrite(json_encode($dataZaznamu, JSON_UNESCAPED_UNICODE) . PHP_EOL);
$fsData->flock(LOCK_UN);
}
if (!isset($this->mapovaniPozicCache[$table][$idStr])) {
$this->mapovaniPozicCache[$table][$idStr] = [];
}
// Přidáme pozici na začátek pole historie verzí (Index 0 = nejnovější stav)
array_unshift($this->mapovaniPozicCache[$table][$idStr], [
'seg' => $segment,
'adr' => $bajtovaAdresa,
'_row_id' => $rowId
]);
// Oříznutí historie verzí v paměti RAM na maximálně 5 stavů
if (count($this->mapovaniPozicCache[$table][$idStr]) > 5) {
array_pop($this->mapovaniPozicCache[$table][$idStr]);
}
// Okamžitá synchronizace cache z RAM na pevný disk do souboru .rowidx
$this->ulozMapaIndexuNaDisk($table);
// Zápis hodnot do sekundárních fixních .idx indexů
$this->aktualizujSekundarniIndexy($table, $idStr, $dataZaznamu);
return $idStr;
}

    /**
     * STREAMOVANÝ FULLTEXTOVÝ VYHLEDÁVAČ PRO ADMINISTRACI
     * Otevírá každý soubor pouze jednou a bleskově filtruje texty i pole řádek po řádku.
     */
    private function provedFulltextSearch(string $table, array $params): array {
        $hledanySloupec = isset($params['search_col']) ? trim((string)$params['search_col']) : '';
        $hledanaHodnota = isset($params['search_val']) ? trim((string)$params['search_val']) : '';
        $limit = (int)($params['limit'] ?? 20);
        $stranka = (int)($params['page'] ?? 1);

        $filtrovanaData = [];
        $hledatMalymi = mb_strtolower($hledanaHodnota, 'UTF-8');

        // Najdeme všechny existující datové segmenty pro tuto tabulku
        $segmenty = glob(DB_PATH . "/json/{$table}_*.db") ?: [];
        sort($segmenty);

        foreach ($segmenty as $cestaSekmentu) {
            if (!file_exists($cestaSekmentu)) continue;

            // Otevřeme celý segment pouze jednou a čteme ho streamovaně řádek po řádku
            $fsData = new SplFileObject($cestaSekmentu, 'r');
            while (!$fsData->eof()) {
                $line = trim($fsData->fgets());
                if (empty($line)) continue;

                $radek = json_decode($line, true);
                if (!is_array($radek) || isset($radek['_deleted'])) continue;

                // Pokud nic nehledáme, bereme řádek automaticky (pro základní výpis tabulky)
                if (empty($hledanaHodnota)) {
                    $filtrovanaData[] = $radek;
                    continue;
                }

                if (!empty($hledanySloupec)) {
                    // --- VARIANTA A: HLEDÁME V KONKRÉTNÍM SLOUPCI ---
                    if (isset($radek[$hledanySloupec])) {
                        $bunka = $radek[$hledanySloupec];
                        if (is_array($bunka)) {
                            foreach ($bunka as $prvek) {
                                if (strpos(mb_strtolower((string)$prvek, 'UTF-8'), $hledatMalymi) !== false) {
                                    $filtrovanaData[] = $radek;
                                    break;
                                }
                            }
                        } else {
                            if (strpos(mb_strtolower((string)$bunka, 'UTF-8'), $hledatMalymi) !== false) {
                                        $filtrovanaData[] = $radek;
                            }
                        }
                    }
                } else {
                    // --- VARIANTA B: HLEDÁME GLOBÁLNĚ NAPŘÍČ VŠEMI SLOUPCI ---
                    $nalezenoVRadku = false;
                    foreach ($radek as $klic => $hodnotaBunky) {
                        if (strpos($klic, '_') === 0 || $klic === 'id') continue;

                        if (is_array($hodnotaBunky)) {
                            foreach ($hodnotaBunky as $prvek) {
                                if (strpos(mb_strtolower((string)$prvek, 'UTF-8'), $hledatMalymi) !== false) {
                                    $nalezenoVRadku = true;
                                    break;
                                }
                            }
                        } else {
                            if (strpos(mb_strtolower((string)$hodnotaBunky, 'UTF-8'), $hledatMalymi) !== false) {
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
        }

        // Výpočet paginace (stránkování) přímo uvnitř vyhledávače
        $celkemPolozek = count($filtrovanaData);
        
        // Pokud je nastaveno řazení (např. podle času)
        if (!empty($params['orderBy'])) {
            $f = $params['orderBy'];
            usort($filtrovanaData, fn($a, $b) => ($a[$f] ?? '') <=> ($b[$f] ?? ''));
        }

        $offset = ($stranka - 1) * $limit;
        $orezanaData = array_slice($filtrovanaData, $offset, $limit);

        return [
            'records' => $orezanaData,
            'celkem' => $celkemPolozek,
            'stranek' => ceil($celkemPolozek / $limit),
            'aktualni_stranka' => $stranka
        ];
    }


/**
* 2. RYCHLÝ SELECT: Automatická detekce .idx souborů a de-atomizace dat na texty
*/
private function provedSelect(string $table, array $params): array {
$w = $params['where'] ?? [];
$vysledky = [];
// Cesta A: Přímý skok podle textového ID (Složitost O(1) - absolutní rychlost)
if (!empty($w['id'])) {
$idStr = (string)$w['id'];
if (isset($this->mapovaniPozicCache[$table][$idStr])) {
$pozice = $this->mapovaniPozicCache[$table][$idStr][0];
$fsData = new SplFileObject($this->generujCestuKSegmentu($table, $pozice['seg']), 'r');
$fsData->fseek($pozice['adr']);
$z = json_decode(trim($fsData->fgets()), true);
if ($z) $vysledky[] = $z;
}
return $vysledky;
}
// Cesta B: FLEXIBILNÍ DETEKCE INDEXŮ - Vyhledá jakýkoliv .idx soubor na disku
$pouzitIndexSloupec = null;
        // POJISTKA: Pokud je poslán příkaz ignore_idx (např. z vyhledávače v administraci), diskové indexy vynecháme
        if (empty($params['ignore_idx'])) {
            foreach (array_keys($w) as $hledanySloupec) {
                $cestaIdx = DB_PATH . "/json/index_{$table}_{$hledanySloupec}.idx";
                if (file_exists($cestaIdx)) {
                    $pouzitIndexSloupec = $hledanySloupec;
                    break;
                }
            }
        }
if ($pouzitIndexSloupec !== null) {
// Letíme sub-milisekundově přes binární vyhledávací index soubor
$vysledky = $this->ctiPresSekundarniIndex($table, $pouzitIndexSloupec, (string)$w[$pouzitIndexSloupec]);
}
// Cesta C: Rychlý full-scan z předem načtené paměťové cache v RAM
else {
foreach ($this->mapovaniPozicCache[$table] as $idStr => $verze) {
if (!empty($verze) && isset($verze[0])) {
$pozice = $verze[0];
$fsData = new SplFileObject($this->generujCestuKSegmentu($table, $pozice['seg']), 'r');
$fsData->fseek($pozice['adr']);
$z = json_decode(trim($fsData->fgets()), true);
if ($z) $vysledky[] = $z;
}
}
}
// DE-ATOMIZACE HODNOT PŘI VÝSTUPU: Přeložení tečkovaných ID zpět na lidské texty
if (!empty($vysledky)) {
$cestaGrafu = DB_PATH . "/globalni_graf_vazeb.json";
$graf = file_exists($cestaGrafu) ? json_decode(@file_get_contents($cestaGrafu), true) : [];
foreach ($vysledky as &$row) {
foreach ($row as $klic => &$bunka) {
if (is_string($bunka) && substr($bunka, -1) === '.' && isset($graf[$bunka])) {
// Vytáhneme textové jméno schované pod ID klíčem v grafu
$bunka = array_key_first($graf[$bunka]);
}
}
}
unset($bunka, $row);
// Dodatečná filtrace WHERE pro neindexované podmínky dotazu
if (!empty($w)) {
$vysledky = array_values(array_filter($vysledky, function($row) use ($w) {
foreach ($w as $k => $v) {
if ($k === 'id') continue;
if (!array_key_exists($k, $row) || $row[$k] != $v) return false;
}
return true;
}));
}
}
// Řazení podle času nebo libovolného klíče
if (!empty($params['orderBy'])) {
$f = $params['orderBy'];
usort($vysledky, fn($a, $b) => ($a[$f] ?? '') <=> ($b[$f] ?? ''));
}
// Stránkování (Limit / Offset) pro webové rozhraní
if (!empty($params['limit'])) {
$vysledky = array_slice($vysledky, 0, (int)$params['limit']);
}
return array_values($vysledky);
}
/**
* 3. FUNKČNÍ DELETE: Spolehlivě maže vlastnosti podle textového uzel_id
*/
private function provedDelete(string $table, array $params): int {
$smazanoPocet = 0;
if (!empty($params['id'])) {
$idStr = (string)$params['id'];
if (isset($this->mapovaniPozicCache[$table][$idStr])) {
unset($this->mapovaniPozicCache[$table][$idStr]);
$smazanoPocet = 1;
}
} elseif (!empty($params['where'])) {
// Vyhledáme záznamy odpovídající podmínce a promažeme je z RAM cache
$nalezené = $this->provedSelect($table, ['where' => $params['where']]);
foreach ($nalezené as $zaznam) {
if (isset($zaznam['id'])) {
unset($this->mapovaniPozicCache[$table][(string)$zaznam['id']]);
$smazanoPocet++;
}
}
}
if ($smazanoPocet > 0) {
$this->ulozMapaIndexuNaDisk($table);
}
return $smazanoPocet;
}
/**
* 4. ATOMIZACE NA VYŽÁDÁNÍ: Spouští se pouze pro schématicky propojené sloupce
*/
private function atomizujDataNaVyzadani(string $table, array $data): array {
$cestaGrafu = DB_PATH . "/globalni_graf_vazeb.json";
if (!file_exists($cestaGrafu)) return $data;
$graf = json_decode(@file_get_contents($cestaGrafu), true) ?? [];
$idTabulky = null;
foreach ($graf as $klic => $blok) {
if (is_array($blok) && isset($blok[$table])) { $idTabulky = $klic; break; }
}
if (!$idTabulky) return $data; // Žádné schéma není navoleno -> vracíme čistá data bez režie
foreach ($data as $sloupec => &$hodnota) {
if (isset($graf[$idTabulky][$table][$sloupec])) {
$idSloupce = $graf[$idTabulky][$table][$sloupec];
$idHodnoty = $this->ziskejIdHodnotyZGrafu($graf, (string)$hodnota, $idTabulky, $idSloupce);
if (!isset($graf[$idHodnoty][$hodnota])) $graf[$idHodnoty] = [$hodnota => []];
if (!in_array($idTabulky, $graf[$idHodnoty][$hodnota], true)) $graf[$idHodnoty][$hodnota][] = $idTabulky;
file_put_contents($cestaGrafu, json_encode($graf, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
$hodnota = $idHodnoty;
}
}
return $data;
}

/**
* 6. HISTORIE VERZÍ: Načtení konkrétního historického stavu z paměťové cache pozic
*/
    /**
     * HISTORIE VERZÍ: Získání konkrétní verze (0 = nejnovější, 1-4 = starší stavy)
     */
    public function selectVersion(string $table, string $id, int $verzeIndex = 0): ?array {
        $this->inicializujTabulku($table);
        $idStr = (string)$id;
        
        if (!isset($this->mapovaniPozicCache[$table][$idStr][$verzeIndex])) return null;
        
        // Správné vytažení segmentu a adresy z naší paměťové RAM cache!
        $pozice = $this->mapovaniPozicCache[$table][$idStr][$verzeIndex];
        $cestaDat = $this->generujCestuKSegmentu($table, (int)$pozice['seg']);
        
        if (file_exists($cestaDat)) {
            $fsData = new SplFileObject($cestaDat, 'r');
            $fsData->fseek($pozice['adr']);
            $z = json_decode(trim($fsData->fgets()), true);
            
            if ($z) {
                $cestaGrafu = DB_PATH . "/globalni_graf_vazeb.json";
                $graf = file_exists($cestaGrafu) ? json_decode(@file_get_contents($cestaGrafu), true) : [];
                foreach ($z as $klic => &$bunka) {
                    if (is_string($bunka) && substr($bunka, -1) === '.' && isset($graf[$bunka])) {
                        $bunka = array_key_first($graf[$bunka]);
                    }
                }
                unset($bunka);
            }
            return $z;
        }
        return null;
    }

public function getHistory(string $table, string $id): array {
$historie = [];
for ($i = 0; $i < 5; $i++) {
$v = $this->selectVersion($table, $id, $i);
if ($v) $historie[] = $v;
}
return $historie;
}

    /**
     * ZÁPIS DO CENTRÁLNÍHO LOGU ZMĚN (S fixním 30B počítadlem na 1. řádku pro asynchronní GC)
     */
    private function zapisDoCentralnihoLogu(string $table, string $id, string $akce, float $durationMs): void {
        $adresarLogu = DB_PATH . '/centralni_logy';
        if (!is_dir($adresarLogu)) mkdir($adresarLogu, 0755, true);

        $logy = glob($adresarLogu . '/log_*.db') ?: [];
        $cestaLogu = !empty($logy) ? end($logy) : '';
        
        // Pokud log neexistuje nebo překročil limit velikosti, založíme nový
        $založitNovy = empty($cestaLogu) || (filesize($cestaLogu) >= $this->maxVelikostLogu);
        
        if ($založitNovy) {
            $cestaLogu = $adresarLogu . '/log_' . date('Y-m-d_H-i-s') . '.db';
            $fpInit = new SplFileObject($cestaLogu, 'w');
            // Zápis fixní 30-bajtové hlavičky s mezerami
            $fpInit->fwrite("COUNT:000000                 " . PHP_EOL);
            $fpInit = null;
        }

        $soubor = new SplFileObject($cestaLogu, 'c+'); 
        if ($this->bezpecnyLock($soubor, LOCK_EX)) {
            // 1. Čtení a inkrementace fixního počítadla na 1. řádku
            $soubor->rewind();
            $hlavicka = $soubor->fgets();
            preg_match('/COUNT:(\d+)/', $hlavicka, $matches);
            $aktualniPocet = isset($matches[1]) ? (int)$matches[1] : 0;
            $novyPocet = $aktualniPocet + 1;

            // Přepsání přesných bajtů hlavičky bez posunu zbytku souboru
            $soubor->rewind();
            $soubor->fwrite(sprintf("COUNT:%06d                 ", $novyPocet) . PHP_EOL);

            // 2. Zápis samotného logu na konec souboru logu
            $soubor->seek(PHP_INT_MAX);
            $zaznam = [
                'ts' => microtime(true),
                'tabulka' => $table,
                'id' => $id,
                'akce' => $akce,
                'user_id' => $this->ziskejAktualnihoUzivatele(),
                'duration_ms' => round($durationMs, 2),
                'soubor' => basename($_SERVER['SCRIPT_FILENAME'] ?? 'unknown')
            ];
            $soubor->fwrite(json_encode($zaznam, JSON_UNESCAPED_UNICODE) . PHP_EOL);
            $soubor->flock(LOCK_UN);

            // AUTOMATICKÉ SPUŠTĚNÍ ČISTIČE NA POZADÍ PŘI DOSAŽENÍ LIMITU ZMĚN (např. 5000)
            // Použijeme pevnou hodnotu 5000 jako limit změn pro celkovou stabilizaci disku
            if ($novyPocet >= 5000) {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request(); // Ukončíme spojení s prohlížečem, uživatel nečeká
                }
                $this->garbageCollector($table); // Spustí hloubkový úklid na pozadí serveru
            }
        }
    }

/**
* 7. SAMOOPTIMALIZAČNÍ ANALÝZA: Sleduje dotazy a zakládá trvalé binární indexy na pozadí serveru
*/
private function analyze(string $table, string $action, array $params, float $duration): void {
if ($this->vnitrniBlokaceAnalizy || $this->kontrolaAGlobalniLock('check')) return;
$this->queryCount++;
if ($duration > 50) {
Logger::log("Pomalý dotaz: {$duration}ms", "warning", compact('table', 'action'));
}

        // Zajímají nás pouze dotazy typu SELECT, které filtrují přes WHERE podmínky
        if ($action === 'select' && isset($params['where']) && is_array($params['where'])) {
            $cestaStatistiky = DB_PATH . "/statistika_dotazu_{$table}.json";
            
            // Otevření nebo vytvoření sdíleného souboru statistik dotazů napříč relacemi
            $fpStat = new SplFileObject(file_exists($cestaStatistiky) ? $cestaStatistiky : touch($cestaStatistiky), 'c+');
            if ($this->bezpecnyLock($fpStat, LOCK_EX)) {
                $obsah = ''; 
                $fpStat->rewind();
                while (!$fpStat->eof()) {
                    $obsah .= $fpStat->fgets();
                }
                
                $statData = json_decode(trim($obsah), true) ?? ['_celkem' => 0, 'sloupce' => []];
                
                foreach (array_keys($params['where']) as $col) {
                    $sloupec = (string)$col;
                    if ($sloupec === 'id') continue; // ID má nativní O(1) přístup, netřeba indexovat
                    
                    $statData['sloupce'][$sloupec] = ($statData['sloupce'][$sloupec] ?? 0) + 1;
                }
                $statData['_celkem']++;

                // Jakmile databáze zaznamená 100 dotazů globálně od všech návštěvníků webu
                if ($statData['_celkem'] >= 100) {
                    arsort($statData['sloupce']);
                    
                    foreach ($statData['sloupce'] as $sloupec => $pocetHledani) {
                        $cestaIdx = DB_PATH . "/json/index_{$table}_{$sloupec}.idx";
                        
                        // Podmínka flexibilní rychlosti: Více než 30 % dotazů vyžadovalo sloupec a index ještě na disku neleží
                        if ($pocetHledani > 30 && !file_exists($cestaIdx)) {
                            
                            // ASYNCHRONNÍ UKONČENÍ REQUESTU: Odešleme web prohlížeči a údržbu provedeme skrytě na pozadí
                            if (function_exists('fastcgi_finish_request')) {
                                fastcgi_finish_request();
                            }

                            // Aktivujeme globální locks pro ochranu před kolizemi zápisů
                            $this->kontrolaAGlobalniLock('lock');
                            $this->vnitrniBlokaceAnalizy = true;

                            Logger::log("Samooptimalizace: Automaticky generuji trvalý binární index pro '{$table}.{$sloupec}' ({$pocetHledani}x hledáno).", "system");

                            // Založíme prázdný .idx soubor na disku
                            touch($cestaIdx);

// RETROAKTIVNÍ DOINDEXOVÁNÍ STARÝCH DATA DO SOUBORU .IDX
if (isset($this->mapovaniPozicCache[$table])) {
    foreach ($this->mapovaniPozicCache[$table] as $idStr => $verze) {
        // Kontrola, že pole verzí není prázdné a obsahuje nejnovější stav na indexu 0
        if (empty($verze) || !isset($verze[0])) continue;
        
        // OPRAVENO: Sáhneme si pro aktuální stav na index 0
        $pozice = $verze[0]; 
        $fsData = new SplFileObject($this->generujCestuKSegmentu($table, (int)$pozice['seg']), 'r');
        $fsData->fseek((int)$pozice['adr']);
        $zaznam = json_decode(trim($fsData->fgets()), true);

                                    
                                    if (is_array($zaznam) && isset($zaznam[$sloupec]) && !empty($zaznam[$sloupec])) {
                                        $hash = substr(md5((string)$zaznam[$sloupec]), 0, 16);
                                        $fsIdx = new SplFileObject($cestaIdx, 'a+');
                                        if ($this->bezpecnyLock($fsIdx, LOCK_EX)) {
                                            $fsIdx->seek(PHP_INT_MAX);
                                            $fsIdx->fwrite(sprintf("%-16s%-31s\n", $hash, substr((string)$idStr, 0, 31)));
                                            $fsIdx->flock(LOCK_UN);
                                        }
                                    }
                                }
                            }
                            
                            // Úklid dokončen, uvolníme locks
                            $this->vnitrniBlokaceAnalizy = false;
                            $this->kontrolaAGlobalniLock('unlock');
                        }
                    }
                    // Vynulování sdílených statistik pro další vlnu 100 požadavků
                    $statData = ['_celkem' => 0, 'sloupce' => []];
                }

                $fpStat->ftruncate(0);
                $fpStat->rewind();
                $fpStat->fwrite(json_encode($statData));
                $fpStat->flock(LOCK_UN);
            }
        }
    }

    /*** 8. SEKUNDÁRNÍ INDEXY: Zápis a čtení fixních 48B bloků [HashHodnoty(16B) | Textové_ID(31B) | \n] */
    private function aktualizujSekundarniIndexy(string $table, string $idStr, array $data): void {
        foreach ($data as $sloupec => $hodnota) {
            // Ignorujeme vnitřní systémové klíče, ID a prázdné obsahy
            if ($sloupec === 'id' || strpos($sloupec, '_') === 0 || empty($hodnota)) continue;

            $cestaIdx = DB_PATH . "/json/index_{$table}_{$sloupec}.idx";
            
            // Index doplňujeme pouze v případě, že už na disku .idx soubor fyzicky žije
            if (file_exists($cestaIdx)) {
                $hash = substr(md5((string)$hodnota), 0, 16);
                $fsIdx = new SplFileObject($cestaIdx, 'a+');
                if ($this->bezpecnyLock($fsIdx, LOCK_EX)) {
                    $fsIdx->seek(PHP_INT_MAX);
                    $fsIdx->fwrite(sprintf("%-16s%-31s\n", $hash, substr($idStr, 0, 31)));
                    $fsIdx->flock(LOCK_UN);
                }
            }
        }
    }

    /**
     * Vyhledá data v binárním 48B indexu a vrátí čisté pole odpovídajících řádků
     */
    private function ctiPresSekundarniIndex(string $table, string $sloupec, string $hodnota): array {
        $cestaIdx = DB_PATH . "/json/index_{$table}_{$sloupec}.idx";
        if (!file_exists($cestaIdx)) return [];
        
        $hledanyHash = substr(md5($hodnota), 0, 16);
        $nalezneneIds = [];

        // Čtení fixních 48-bajtových bloků z disku
        $fsIdx = new SplFileObject($cestaIdx, 'r');
        while (!$fsIdx->eof()) {
            $blok = $fsIdx->fread(48); 
            if (strlen($blok) < 48) break;
            
            if (trim(substr($blok, 0, 16)) === $hledanyHash) {
                $nalezneneIds[] = trim(substr($blok, 16, 31));
            }
        }

        $records = [];
        foreach ($nalezneneIds as $id) {
            // Voláme provedSelect přímo pro konkrétní ID. To skočí O(1) do .rowidx.
            $r = $this->provedSelect($table, ['where' => ['id' => $id]]);
            if (!empty($r) && is_array($r)) {
                // OPRAVA: provedSelect vrací pole obsahující jeden řádek (např. [0 => [...]] )
                // Do výsledků musíme vložit přímo ten řádek, ne celé pole, abychom neměli pole v poli!
                $records[] = isset($r[0]) ? $r[0] : $r; 
            }
        }
        return $records;
    }


    /*** 9. ATOMIZACE SCHÉMATU: Správa strukturálních propojení sloupců v grafu na vyžádání */
    private function runSchema(string $table, array $params): mixed {
        $task = $params['task'] ?? '';
        $cestaGrafu = DB_PATH . "/globalni_graf_vazeb.json";
        if (!file_exists($cestaGrafu)) touch($cestaGrafu);

        $soubor = new SplFileObject($cestaGrafu, 'c+');
        if (!$this->bezpecnyLock($soubor, LOCK_EX)) return false;
        
        $graf = $this->nactiGlobalniGraf($soubor);
        $idTabulky = $this->ziskejIdEntityZGrafu($graf, $table, 'table');
        $uspech = false;

        switch ($task) {
            case 'link_columns':
                $sloupecSrc = $params['column'] ?? '';
                $targetTable = $params['target_table'] ?? '';
                $sloupecTrg = $params['target_column'] ?? '';
                $idTargetTab = $this->ziskejIdEntityZGrafu($graf, $targetTable, 'table');

                // Vygenerujeme unikátní a nezaměnitelná ID pro propojené sloupce (Předchází konfliktům stejných názvů)
                $idSrcCol = 'C_' . substr(md5($table . $sloupecSrc), 0, 4) . '.';
                $idTrgCol = 'C_' . substr(md5($targetTable . $sloupecTrg), 0, 4) . '.';

                $graf[$idTabulky][$table][$sloupecSrc] = $idSrcCol;
                $graf[$idTargetTab][$targetTable][$sloupecTrg] = $idTrgCol;

                // Strukturální propojení v mapě schématu (Sekce "1." a "3.")
                $graf[$idTabulky][$table][$idTargetTab] = [$sloupecTrg => $idTrgCol];
                $graf[$idTargetTab][$targetTable][$idTabulky] = [$sloupecSrc => $idSrcCol];
                $uspech = true;
                break;

            case 'rename_column':
                $stary = $params['column'] ?? '';
                $novy  = $params['new_name'] ?? '';
                
                $idSloupce = null;
                foreach ($graf as $k => $d) {
                    if (substr($k, -1) === '.' && isset($d[$stary])) { 
                        $idSloupce = $k; 
                        break; 
                    }
                }

                if ($idSloupce !== null) {
                    $struktura = $graf[$idSloupce][$stary];
                    if (!isset($graf[$idSloupce]['_historie_nazvu'])) {
                        $graf[$idSloupce]['_historie_nazvu'] = [$stary];
                    }
                    
                    array_unshift($graf[$idSloupce]['_historie_nazvu'], $novy);
                    if (count($graf[$idSloupce]['_historie_nazvu']) > 5) {
                        array_pop($graf[$idSloupce]['_historie_nazvu']);
                    }

                    unset($graf[$idSloupce][$stary]);
                    $graf[$idSloupce][$novy] = $struktura;

                    if (isset($graf[$idTabulky][$table][$stary])) {
unset($graf[$idTabulky][$table][$stary]);
$graf[$idTabulky][$table][$novy] = $idSloupce;
}
$uspech = true;
}
break;
}
if ($uspech) {
$soubor->ftruncate(0);
$soubor->rewind();
$soubor->fwrite(json_encode($graf, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
$soubor->flock(LOCK_UN);
return $uspech;
}
private function ziskejIdEntityZGrafu(array &$graf, string $jmeno, string $typ): string {
foreach ($graf as $klic => $data) {
if (is_array($data) && isset($data[$jmeno])) return (string)$klic;
}
$maxId = 0;
foreach (array_keys($graf) as $klic) {
if (is_numeric(rtrim($klic, '.'))) $maxId = max($maxId, (int)rtrim($klic, '.'));
}
$noveId = ($maxId + 1) . '.';
if ($typ === 'table') $graf[$noveId] = [$jmeno => []];
return $noveId;
}
private function ziskejIdHodnotyZGrafu(array &$graf, string $hodnota, string $idTabulky, string $idSlouce): string {
foreach ($graf as $klic => $data) {
if (substr($klic, -1) === '.' && isset($data[$hodnota])) return (string)$klic;
}
$maxId = 0;
foreach (array_keys($graf) as $klic) {
if (is_numeric(rtrim($klic, '.'))) $maxId = max($maxId, (int)rtrim($klic, '.'));
}
$noveId = ($maxId + 1) . '.';
$graf[$noveId] = [$hodnota => [$idTabulky]];
return $noveId;
}
public function ziskejHodnotuZGrafu(string $idHodnoty): ?array {
$cestaGrafu = DB_PATH . "/globalni_graf_vazeb.json";
if (!file_exists($cestaGrafu)) return null;
$graf = json_decode(@file_get_contents($cestaGrafu), true) ?? [];
if (isset($graf[$idHodnoty])) {
$hodnotaText = array_key_first($graf[$idHodnoty]);
return ['hodnota' => $hodnotaText, 'dotcene_tabulky' => $graf[$idHodnoty][$hodnotaText]];
}
return null;
}
/*** 10. KOMPLETNÍ ČISTIČ PRO DYNAMICKO-BAJTOVÝ ENGINE (GARBAGE COLLECTOR) */
public function garbageCollector(string $table): array {
if ($this->kontrolaAGlobalniLock('check')) {
return ['success' => false, 'error' => 'Údržba již probíhá v jiné relaci.'];
}
$this->kontrolaAGlobalniLock('lock');
$this->vnitrniBlokaceAnalizy = true;
$start = microtime(true);
$this->inicializujTabulku($table);
$vsechnaPlatnaDataSVezemi = [];
// 1. Načtení platné historie verzí z fragmentů disku do paměti RAM
foreach ($this->mapovaniPozicCache[$table] as $id => $verze) {
$vsechnaPlatnaDataSVezemi[$id] = [];
foreach ($verze as $pozice) {
$cestaDat = $this->generujCestuKSegmentu($table, $pozice['seg']);
if (file_exists($cestaDat)) {
$fs = new SplFileObject($cestaDat, 'r');
$fs->fseek($pozice['adr']);
$d = json_decode(trim($fs->fgets()), true);
if ($d) $vsechnaPlatnaDataSVezemi[$id][] = $d;
}
}
}
// 2. Kompletní vyčištění starých datových fragmentů a rowidx souborů
foreach (glob(DB_PATH . "/json/{$table}_*.db") ?: [] as $f) @unlink($f);
@unlink(DB_PATH . "/json/{$table}.rowidx");
// POZNÁMKA: Sekundární .idx soubory nemažeme nadobro, aby zůstaly flexibilně rychlé.
// Následující smyčka zápisu je automaticky bleskově přepočítá do čistého srovnaného stavu.
foreach (glob(DB_PATH . "/json/index_{$table}_*.idx") ?: [] as $f) @unlink($f);
// Reset paměťové cache v RAM
$this->mapovaniPozicCache[$table] = [];
touch(DB_PATH . "/json/{$table}.rowidx");
// 3. Čistý zpětný zápis platných dat (Oříznuto na max 5 historií, seřazeno chronologicky správně)
foreach ($vsechnaPlatnaDataSVezemi as $id => $verzePole) {
foreach (array_reverse($verzePole) as $zaznam) {
// Vyčistíme staré časové značky úprav před novým srovnáním
unset($zaznam['updated_at'], $zaznam['_zmeneno'], $zaznam['_autor_zmeny']);
$this->provedUpsertZapis($table, $zaznam);
}
}
// 4. Oříznutí starých centrálních logů (Ponechá max 5 nejnovějších souborů žurnálu)
$vsechnyLogy = glob(DB_PATH . '/centralni_logy/log_*.db') ?: [];
if (count($vsechnyLogy) > 5) {
sort($vsechnyLogy);
while (count($vsechnyLogy) > 5) {
@unlink(array_shift($vsechnyLogy));
}
}
// Vynulování průběžného čítače na 1. řádku aktuálního logu
$cestaLoguAktualni = glob(DB_PATH . '/centralni_logy/log_*.db') ?: [];
if (!empty($cestaLoguAktualni)) {
$posledniLog = end($cestaLoguAktualni);
$souborLog = new SplFileObject($posledniLog, 'c+');
if ($this->bezpecnyLock($souborLog, LOCK_EX)) {
$souborLog->rewind();
$souborLog->fwrite("COUNT:000000 " . PHP_EOL);
$souborLog->flock(LOCK_UN);
}
}
$this->vnitrniBlokaceAnalizy = false;
$this->kontrolaAGlobalniLock('unlock');
return [
'success' => true,
'table' => $table,
'records_count' => count($this->mapovaniPozicCache[$table]),
'trvani_ms' => round((microtime(true) - $start) * 1000, 2)
];
}
}
