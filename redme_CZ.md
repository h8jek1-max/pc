Pokyn AI: 
Potřebuji napsat kompletní, produkční a plně funkční embedded (souborovou) databázi v čistém PHP 8.2+, která kombinuje principy sloupcové indexace, grafových vazeb a pokročilého managementu paměti na nejnižší hardwarové úrovni. Kód musí být připraven k přímému použití, bez pseudokódu, s maximálním důrazem na ošetření chyb, bezpečnost a atomicitu operací.

Níže je striktní architektonický blueprint, který musíš do puntíku implementovat:

### 1. STRUKTURA SOUBOROVÉHO ÚLOŽIŠTĚ (Vše v jednom souboru `database.db`)
Soubor je vnitřně rozdělen na tři izolované zóny:
*   **typ A (Indexová směrovací tabulka - Pevná délka řádku):** Každý řádek má konstantní délku 12 bajtů: [4 bajty: Fyzický offset v typu B] + [4 bajty: Délka bloku v typu B] + [4 bajty: Typ uzlu (0=Data, 1=Strom, 2=Bísekce)]. typ A funguje jako stabilní prostředník – rowid v typu A se nikdy nemění, i když se data v typu B stěhují.
*   **typ B (Datové haldy a uzly - Proměnlivá délka řádků):** Zde jsou uloženy surové řádky tabulky, uzly komprimovaného stromu a indexové stránky. Položky jednoho řádku tabulky (buňky všech sloupců) jsou zapsány fyzicky vedle sebe pro bleskové sekvenční čtení.
*   **typ C - jeden řádek na který odkazuje vstupní řádek typu A, který je na samotném začátku souboru (offset 0) a je pevná hlavička (jeden řádek rowidx s konstantními 12. bajty). typ C je proměnlivý řádek stejně jako B, jen má pevný vstupní rowidx typu A na začátku souboru (Metadata a konfigurace):** je typ disku, pointery na kořenové uzly a velikosti prázdných míst po smazaných položkách mezi ostatními.

struktura: ZÓNA A (Indexová tabulka - s recyklací, pokud není volné místo append-only):
  rowid 0 → ukazuje na Zónu C (metadata)
  rowid 1 → ukazuje na Zónu B (data row 1)
  rowid 2 → ukazuje na Zónu B (data row 2)
  ...

ZÓNA B (Data - proměnlivá délka, append-only s recyklací):
  [JSON metadat] [data řádků] [stromy] [B+Tree stránky] [cache sql dotazů a jejich výsledků]

ZÓNA C (Metadata - proměnlivá délka, append-only s recyklací, ale rowid 0 v Zóně A):
  JSON s:
  - typ disku
  - page size
  - definice tabulek
  - kořeny indexů
  - díry
  - cache informace
  
✅ Zóna A - recyklace (volné rowidy), pokud není, append-only

✅ Zóna B - append-only s recyklací děr (Best-Fit)

✅ Zóna C - metadata jako rowid 0 v Zóně A, data v Zóně B

✅ Cache - perzistentní v Zóně B, přežije restart

✅ Všechny zóny - plná recyklace místa

✅ Žádné předalokování - soubor roste přirozeně

### 2. DYNAMICKÁ DETEKCE HARDWARU (SSD vs HDD)
Při inicializaci databáze (nebo z načtené konfigurace) engine zjistí typ disku serveru (přes /sys/block/ na Linuxu nebo PowerShell na Windows). Pokud nebude tato možnost zapne se výchozí HDD.
*   **Pokud SSD:** Velikost indexového bloku (Page Size) se nastaví na 4096 bajtů (4 KB). Aktivuje se BLOB režim (velké texty nad 255 bajtů se ukládají do externích souborů).
*   **Pokud HDD:** Velikost indexového bloku se zvětší na 65536 bajtů (64 KB) pro minimalizaci mechanických skoků hlavičky disku. BLOB režim se deaktivuje (všechny texty, kromě těch největších, zůstávají v řádku tabulky kvůli sekvenčnímu čtení).

### 3. ADVANCED INDEXACE A VYHLEDÁVÁNÍ
Engine musí podporovat dva typy indexů v rámci jednoho souboru řízené bajtem 'Typ' z typu A:
*   **Komprimovaný Prefixový Strom (Radix Tree) pro TEXT:** Vyhledávání slov a e-mailů. Uzly jsou uloženy binárně. Každá větev v uzlu obsahuje: [2 bajty: Délka prefixu] + [X bajtů: Text prefixu] + [4 bajty: rowid] + [4 bajty: typ]. Pokud je výskyt slova unikátní, ukazuje větev přímo do DB. Pokud je výskytů více, odkáže přes Zónu A na pod-uzel. Vyhledávání textu nesmí používat textové parsování, pouze posun pointerů v RAM načteného uzlu.
*   **Rozdělené Binární Půlení (B+Tree Pages) pro ČÍSLA a DATA:** Index pro data a čísla je rozdělen na fixní stránky (podle detekovaného bloku 4KB/64KB). Vyhledávání rozsahu (OD-DO) najde binárním půlením startovní stránku, koncovou stránku a data mezi nimi přečte v jednom kuse. Implementuj automatické štěpení stránek (Page Split), pokud se do 4KB/64KB bloku už nevejde nové ID.

### 4. AUTOMATICKÝ MANAGEMENT DĚR A RECYKLACE (Append-Only)
Při změně (`UPDATE`) nebo smazání (`DELETE`) se data nikdy nepřepisují uprostřed souboru ani se soubor neposouvá.
*   Staré místo v typu B nebo A se označí jako neaktivní (status 0).
*   Pozice a velikost této "díry" se zaregistruje do **velikostního koše děr**, který je držen v paměti RAM a v typu C.
*   Při zápisu nových dat engine v RAM bleskově najde nejvhodnější díru (Best-Fit). Pokud je díra větší, rozsekne ji, zapíše data a zbytek vrátí jako menší díru do koše.
*   **Automatické čištění:** Pokud koš děr v RAM dosáhne limitu 100 záznamů, engine automaticky ignoruje a zapomene nejmenší mikro-díry (pod 4 bajty) nebo slepí díry vedle sebe do větší, aby udržel konstantní rychlost vyhledávání volného místa.
*   Nový text se zapisuje výhradně na konec souboru nebo do nalezené optimální díry. V Zóně A se přepíše 12 bajtů na nový offset nebo zapisuje výhradně na konec souboru nebo do nalezené optimální díry.

### 5. EXTERNÍ BLOB ÚLOŽIŠTĚ (Pro režim SSD)
Pokud je aktivní režim SSD a řetězec přesáhne 255 bajtů u HDD více (podle efektivity), v řádku typu B se uloží pouze odkaz. Samotný text se uloží jako `.txt` soubor do zanořené struktury podsložek podle MD5 hashe (např. `/db_files/e1/0a/[hash].txt`), aby v jednom adresáři nebylo nikdy více než pár souborů a nezahlcoval se souborový systém.

### 6. BEZPEČNOST, SOUBĚŽNOST A SKUTEČNÁ OPTIMALIZACE
*   **Souběžnost:** Každá zápisová operace musí být striktně chráněna exkluzivním zámkem `flock($file, LOCK_EX)` s následným `fflush()`. Čtení běží bez zamykání nebo se sdíleným zámkem `LOCK_SH`.
*   **Kompletní Rebuild (OPTIMIZE TABLE):** Implementuj funkci `optimizeTable()`. Tato funkce vytvoří vedlejší soubor `database.tmp`, projde typem A, vykopíruje pouze 100% aktivní řádky a uzly z typu B a A těsně vedle sebe, zcela vynechá všechny díry, nově sestaví a seřadí indexové stránky,  následně bezpečně nahradí starý soubor novým.

### 7. SQL PARSER A QUERY CACHE
*   Napiš jednoduchý vestavěný SQL parser (pomocí regulárních výrazů), který umí zpracovat příkazy: `UPSERT`, `SELECT`, `INSERT`, `UPDATE`, `DELETE` , `OPTIMIZE TABLE` a další pro kompatibilitu s SQL příkazy.
*   Implementuj **Podmíněnou Query Cache** v RAM (simuluj pomocí statického pole nebo APCu) a v typu C. Pokud se databáze změní častěji než 2× za 12 hodin (veď si statistiku změn v hlavičce), cache se pro tuto db zakáže, chache po 12 hodinách beze změny db se zase zapne. Pro stabilní db cache vrací výsledky okamžitě bez přístupu na disk. Kdykoliv dojde ke změně dat u cachované db, cache pro danou db se okamžitě smaže.

Vygeneruj čistý, modulární, objektově orientovaný kód v PHP (např. třída `GraphDb`), který obsahuje kompletní logiku včetně metod `query($sql)`, `pack/unpack` binárních struktur, správy stránek a souborových operací. Kód musí být napsán robustně a bezpečně.

Do třídy databáze implementuj interní mikrosekundový časovač, který u každého provedeného dotazu automaticky změří přesný čas zpracování (od parsování až po finální vrácení dat) a tento čas v sekundách přibalí do výsledné odpovědi jako klíč `execution_time_s`.

Výsledek:
=== SROVNÁVACÍ BENCHMARK ===
Počet záznamů: 500
============================================================

Testuji GraphDb...
  Hotovo
Testuji SQLite...
  Hotovo
Testuji JSON...
  Hotovo

============================================================
VÝSLEDKY
============================================================

Operace                  GraphDb        SQLite         JSON           
----------------------------------------------------------------------
INSERT (500x)            0.0934s        0.0577s        0.0008s        
SELECT by ID (100x)      0.1845s        0.0048s        0.0009s        
SELECT by INDEX (100x)   0.0021s        0.0045s        0.0010s        
SELECT LIKE (100x)       0.0309s        0.0116s        0.0031s        
UPDATE (50x)             0.0830s        2.5618s        0.0012s        
DELETE (30x)             0.0501s        1.4722s        0.0010s        

Velikost souboru         42.3076171875 KB64 KB          43.44140625 KB 

CELKEM                   0.4440s        4.1126s        0.0081s        

GraphDb je 0.11x rychlejší než SQLite


=== BENCHMARK: MyUncompressedGraphDb vs SQLite vs JSON ===

Testing MyUncompressedGraphDb...
  Done.
Testing SQLite...
  Done.
Testing JSON file...
  Done.

=== VÝSLEDKY BENCHMARKU ===

Počet záznamů: 1000

Operace                       GraphDB        SQLite         JSON           
---------------------------------------------------------------------------
CREATE TABLE                  0.0001s        0.1706s        -s             
INSERT (1000x)                0.4421s        0.0825s        0.0141s        
SELECT by ID (100x)           0.4076s        0.0056s        0.0039s        
SELECT by INDEX (100x)        0.3865s        0.0040s        0.0029s        
SELECT LIKE (100x) - našeptávač0.3662s        0.0546s        0.0085s        
SELECT BETWEEN (100x)         0.3636s        0.0495s        0.0073s        
TEXT SEARCH (50x)             0.1787s        0.1554s        0.0048s        
UPDATE (100x)                 0.3552s        5.1587s        0.0053s        
DELETE (50x)                  0.1865s        2.4000s        0.0042s        
UPSERT (50x)                  0.2221s        2.5927s        0.0048s        
BLOB (20x)                    0.0775s        1.0179s        0.0030s        
OPTIMIZE/VACUUM               0.0003s        0.0511s        0.0020s        

=== SOUHRN ===
GraphDB celkem: 2.9863s
SQLite celkem: 11.7426s
JSON celkem: 0.0609s

GraphDB je 0.25x rychlejší než SQLite
