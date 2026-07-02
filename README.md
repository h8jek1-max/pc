Zkouška rychlé souborové databáze s fseek a ajax webu dohromady. 
Vytváří indexi automaticky, myšleno tak, aby byla bezúdržbová. 
Skript byl vytvářen ve spolupráci s AI Gemini, Claude, Deepseek, a databáze především s vyhledavačem AI od Google, všechny použité zdarma. 
Řekl bych, že je docela rychlá, ale můžou se v ní vyskytovat chyby, třeba neošetřené stavy, takže při použití na to myslet. 
Má vlastní logger chyb php, js a jiných informací.


## English Translation

**AI Instruction:** I need to write a complete, production-ready, and fully functional embedded (file-based) database in pure PHP 8.2+ that combines the principles of columnar indexing, graph relationships, and advanced memory management at the lowest hardware level. The code must be ready for direct deployment, free of pseudocode, and written with maximum emphasis on error handling, security, and operation atomicity.

Below is the strict architectural blueprint that you must implement down to the last detail:

### 1. FILE STORAGE STRUCTURE (All-in-one file `database.db`)

The file is internally divided into three isolated zones:

* **Zone A (Index Routing Table - Fixed row length):** Each row has a constant length of 12 bytes: `[4 bytes: Physical offset in Zone B] + [4 bytes: Block length in Zone B] + [4 bytes: Node type (0=Data, 1=Tree, 2=Bisection)]`. Zone A acts as a stable intermediary – the `rowid` in Zone A never changes, even if the data in Zone B moves.
* **Zone B (Data Heaps and Nodes - Variable row length):** This zone stores raw table rows, compressed tree nodes, and index pages. Items within a single table row (cells of all columns) are written physically next to each other to enable lightning-fast sequential reading.
* **Zone C (Metadata and Configuration - Variable length row, but mapped via rowid 0 in Zone A at the very beginning of the file offset 0 with a constant 12-byte header):** This stores the disk type, pointers to root nodes, and the sizes of empty spaces (holes) left after deleted items.

Structure:
**ZONE A (Index Table - with recycling; if no free space is available, append-only):**
rowid 0 → points to Zone C (metadata)
rowid 1 → points to Zone B (data row 1)
rowid 2 → points to Zone B (data row 2)
...

**ZONE B (Data - variable length, append-only with hole recycling):**
`[Metadata JSON] [Row data] [Trees] [B+Tree pages] [SQL query cache and their results]`

**ZONE C (Metadata - variable length, append-only with recycling, but accessed via rowid 0 in Zone A):**
JSON containing:

* disk type
* page size
* table definitions
* index roots
* holes
* cache information

✅ Zone A - recycling (free rowids), if none available, append-only.
✅ Zone B - append-only with hole recycling (Best-Fit).
✅ Zone C - metadata stored as rowid 0 in Zone A, data located in Zone B.
✅ Cache - persistent in Zone B, survives restarts.
✅ All zones - full space recycling.
✅ No pre-allocation - the file grows organically.

### 2. DYNAMIC HARDWARE DETECTION (SSD vs. HDD)

During database initialization (or from the loaded configuration), the engine detects the server's disk type (via `/sys/block/` on Linux or PowerShell on Windows). If detection is unavailable, it defaults to HDD.

* **If SSD:** The index block size (Page Size) is set to 4096 bytes (4 KB). BLOB mode is activated (large texts over 255 bytes are stored in external files).
* **If HDD:** The index block size is increased to 65536 bytes (64 KB) to minimize mechanical disk head seeks. BLOB mode is deactivated (all texts, except the largest ones, remain inside the table row for sequential reading efficiency).

### 3. ADVANCED INDEXING AND SEARCHING

The engine must support two types of indexes within a single file, driven by the 'Type' byte from Zone A:

* **Compressed Prefix Tree (Radix Tree) for TEXT:** Used for searching words and emails. Nodes are stored in binary format. Each branch in a node contains: `[2 bytes: Prefix length] + [X bytes: Prefix text] + [4 bytes: rowid] + [4 bytes: type]`. If the word occurrence is unique, the branch points directly to the DB. If there are multiple occurrences, it points to a sub-node via Zone A. Text searching must not use string parsing, only pointer offsets within the RAM-loaded node.
* **Split Binary Bisection (B+Tree Pages) for NUMBERS and DATES:** The index for dates and numbers is divided into fixed pages (based on the detected 4KB/64KB block). Range queries (FROM-TO) use binary bisection to find the start page and end page, then read all data between them in a single operation. Implement automatic page splitting (Page Split) if a new ID cannot fit into the 4KB/64KB block.

### 4. AUTOMATIC HOLE MANAGEMENT AND RECYCLING (Append-Only)

During an `UPDATE` or `DELETE`, data is never overwritten in the middle of the file, nor is the file shifted.

* The old space in Zone B or Zone A is marked as inactive (status 0).
* The position and size of this "hole" are registered into a **size-segregated hole bin** kept in RAM and in Zone C.
* When writing new data, the engine quickly finds the most suitable hole in RAM using the **Best-Fit** algorithm. If the hole is larger than needed, it splits it, writes the data, and returns the remaining space to the bin as a smaller hole.
* **Automatic Cleanup:** If the hole bin in RAM reaches a limit of 100 records, the engine automatically ignores and discards the smallest micro-holes (under 4 bytes) or merges adjacent holes into a larger one to maintain a constant search speed for free space.
* New text is written exclusively to the end of the file or into an optimal discovered hole. In Zone A, the 12 bytes are overwritten with the new offset.

### 5. EXTERNAL BLOB STORAGE (For SSD Mode)

If SSD mode is active and a string exceeds 255 bytes (or more for HDD depending on efficiency), only a reference is stored in the Zone B row. The actual text is saved as a `.txt` file within a nested subdirectory structure based on its MD5 hash (e.g., `/db_files/e1/0a/[hash].txt`) to ensure no single directory contains more than a few files, preventing filesystem saturation.

### 6. SECURITY, CONCURRENCY, AND GENUINE OPTIMALIZATION

* **Concurrency:** Every write operation must be strictly protected by an exclusive lock `flock($file, LOCK_EX)` followed by `fflush()`. Reads run without locking or with a shared lock `LOCK_SH`.
* **Complete Rebuild (OPTIMIZE TABLE):** Implement an `optimizeTable()` function. This function creates a temporary file `database.tmp`, iterates through Zone A, copies only 100% active rows and nodes from Zone B and Zone A tightly next to each other, completely omits all holes, rebuilds and sorts the index pages, and then safely replaces the old file with the new one.

### 7. SQL PARSER AND QUERY CACHE

* Write a simple built-in SQL parser (using regular expressions) capable of processing commands: `UPSERT`, `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `OPTIMIZE TABLE`, and others for compatibility with SQL standards.
* Implement a **Conditional Query Cache** in RAM (simulated using a static array or APCu) and in Zone C. If the database changes more than twice within 12 hours (track change statistics in the header), the cache is disabled for that database. After 12 hours without changes, the cache is re-enabled. For a stable database, the cache returns results instantly without disk access. Whenever data changes in a cached database, the cache for that specific database is immediately cleared.

Generate clean, modular, object-oriented PHP code (e.g., a `GraphDb` class) containing the complete logic, including `query($sql)` methods, binary structure `pack/unpack`, page management, and file operations. The code must be robust and secure.

Implement an internal microsecond timer into the database class that automatically measures the exact processing time for each executed query (from parsing to the final data return) and includes this time in seconds in the final response under the `execution_time_s` key.

I analyzed the theory in an AI search engine, passed it to Claude, and finalized it in Deepseek.
Result:

# === COMPARATIVE BENCHMARK ===
Number of records: 500

Testing GraphDb...
Done
Testing SQLite...
Done
Testing JSON...
Done

# ============================================================
RESULTS

## Operation                GraphDb        SQLite         JSON

INSERT (500x)            0.0934s        0.0577s        0.0008s

SELECT by ID (100x)      0.1845s        0.0048s        0.0009s

SELECT by INDEX (100x)   0.0021s        0.0045s        0.0010s

SELECT LIKE (100x)       0.0309s        0.0116s        0.0031s

UPDATE (50x)             0.0830s        2.5618s        0.0012s

DELETE (30x)             0.0501s        1.4722s        0.0010s

File Size                42.3076171875 KB64 KB          43.44140625 KB

TOTAL                    0.4440s        4.1126s        0.0081s

GraphDb is 0.11x faster than SQLite (Note: 0.11x the execution time / ~9x faster)

=== BENCHMARK: MyUncompressedGraphDb vs SQLite vs JSON ===

Testing MyUncompressedGraphDb...
Done.
Testing SQLite...
Done.
Testing JSON file...
Done.

=== BENCHMARK RESULTS ===

Number of records: 1000

## Operation                     GraphDB        SQLite         JSON

CREATE TABLE                  0.0001s        0.1706s        -s

INSERT (1000x)                0.4421s        0.0825s        0.0141s

SELECT by ID (100x)           0.4076s        0.0056s        0.0039s

SELECT by INDEX (100x)        0.3865s        0.0040s        0.0029s

SELECT LIKE (100x) - autosugg.0.3662s        0.0546s        0.0085s

SELECT BETWEEN (100x)         0.3636s        0.0495s        0.0073s

TEXT SEARCH (50x)             0.1787s        0.1554s        0.0048s

UPDATE (100x)                 0.3552s        5.1587s        0.0053s

DELETE (50x)                  0.1865s        2.4000s        0.0042s

UPSERT (50x)                  0.2221s        2.5927s        0.0048s

BLOB (20x)                    0.0775s        1.0179s        0.0030s

OPTIMIZE/VACUUM               0.0003s        0.0511s        0.0020s

=== SUMMARY ===
GraphDB total: 2.9863s
SQLite total: 11.7426s
JSON total: 0.0609s

GraphDB is 0.25x faster than SQLite (Note: 0.25x the execution time / 4x faster)

