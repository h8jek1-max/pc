<?php
// /php/mod/dbpr/api/render.php
ob_start();
?>
<style>
.dbpr { display: flex; flex-direction: column; gap: 1rem; }
.dbpr-panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1rem; margin-bottom: 1rem; }
.dbpr-header { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1rem; }
.dbpr-header .form-group { flex: 1; min-width: 150px; margin-bottom: 0; }
.dbpr-table-list { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; max-height: 120px; overflow-y: auto; padding: 0.5rem; background: var(--bg); border-radius: var(--radius); }
.dbpr-table-chip { background: var(--primary-dim); border: 1px solid var(--border); border-radius: 20px; padding: 0.25rem 0.75rem; font-size: 0.8rem; cursor: pointer; transition: all 0.1s; }
.dbpr-table-chip:hover { background: var(--primary); color: white; border-color: var(--primary); }
.dbpr-table-chip.active { background: var(--primary); color: white; border-color: var(--primary); }
.dbpr-data-table { overflow-x: auto; border-radius: var(--radius); border: 1px solid var(--border); }
.dbpr-data-table table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
.dbpr-data-table th, .dbpr-data-table td { padding: 0.5rem; text-align: left; border-bottom: 1px solid var(--border); vertical-align: top; }
.dbpr-data-table th { background: var(--primary-dim); color: var(--text); font-weight: 600; position: sticky; top: 0; cursor: pointer; user-select: none; }
.dbpr-data-table th:hover { background: var(--primary-hover); }
.dbpr-data-table td { max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dbpr-data-table td.dbpr-expandable { cursor: pointer; }
.dbpr-data-table td.dbpr-expandable:hover { background: var(--primary-dim); }
.dbpr-pagination { display: flex; justify-content: center; gap: 0.5rem; margin-top: 1rem; align-items: center; }
.dbpr-query-builder { background: var(--bg); border-radius: var(--radius); padding: 1rem; margin-top: 1rem; }
.dbpr-query-steps { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end; margin-bottom: 1rem; }
.dbpr-query-preview { background: #0d1117; border-radius: 8px; padding: 1rem; font-family: monospace; font-size: 0.75rem; overflow-x: auto; margin: 1rem 0; }
.dbpr-autocomplete { position: relative; }
.dbpr-autocomplete-input { width: 100%; }
.dbpr-autocomplete-results { position: absolute; top: 100%; left: 0; right: 0; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; max-height: 200px; overflow-y: auto; z-index: 1000; display: none; box-shadow: var(--shadow); }
.dbpr-autocomplete-results.show { display: block; }
.dbpr-autocomplete-item { padding: 0.5rem 0.75rem; cursor: pointer; border-bottom: 1px solid var(--border); font-size: 0.8rem; }
.dbpr-autocomplete-item:hover { background: var(--primary-dim); }
.dbpr-modal-bg { position: fixed; inset: 0; background: rgba(0,0,0,0.75); z-index: 8000; display: flex; align-items: center; justify-content: center; padding: 1rem; backdrop-filter: blur(2px); }
.dbpr-modal { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); max-height: 90vh; overflow-y: auto; width: 700px; max-width: 100%; }
.dbpr-modal-header { padding: 1rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
.dbpr-modal-body { padding: 1rem; }
.dbpr-modal-footer { padding: 1rem; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 0.5rem; }
.dbpr-history-item { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 0.5rem; }
.dbpr-linked-item { display: flex; justify-content: space-between; align-items: center; padding: 0.25rem 0; font-size: 0.75rem; }
.dbpr-value-full { word-break: break-word; white-space: normal; max-height: 200px; overflow-y: auto; }
.dbpr-row-form { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; }
.dbpr-row-form input { flex: 1; min-width: 100px; }
</style>

<div class="dbpr" id="dbpr">
    <!-- První panel: Tabulky a vyhledávání -->
    <div class="dbpr-panel">
        <div class="dbpr-header">
            <div class="form-group">
                <label>📋 Tabulka</label>
                <select id="dbpr_table_select" class="input" onchange="DBPR.loadTableData(); DBPR.loadColumns();">
                    <option value="">-- Vyberte tabulku --</option>
                </select>
            </div>
            <div class="form-group">
                <label>🔍 Sloupec (nepovinné)</label>
                <select id="dbpr_col_input" class="input" onchange="DBPR.loadTableData()">
                    <option value="">-- Vyberte sloupec --</option>
                </select>
            </div>
            <!--   <div class="form-group">
                <label>🔍 Sloupec (nepovinné)</label>
                <div class="dbpr-autocomplete" id="dbpr_col_ac">
                    <input type="text" id="dbpr_col_input" class="input dbpr-autocomplete-input" placeholder="Hledat sloupec..." autocomplete="off">
                    <div class="dbpr-autocomplete-results" id="dbpr_col_results"></div>
                </div>
            </div>  První panel: Tabulky a vyhledávání -->
            <div class="form-group">
                <label>🔎 Hodnota</label>
                <div class="dbpr-autocomplete" id="dbpr_val_ac">
                    <input type="text" id="dbpr_val_input" onchange="DBPR.loadTableData()" class="input dbpr-autocomplete-input" placeholder="Hledat hodnotu..." autocomplete="off">
                    <div class="dbpr-autocomplete-results" id="dbpr_val_results"></div>
                </div>
            </div>
            <div class="form-group">
                <label>📏 Řádků na stránku</label>
                <select id="dbpr_limit" class="input" onchange="DBPR.loadTableData()">
                    <option value="10">10</option>
                    <option value="20" selected>20</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <button class="btn" onclick="DBPR.loadTableData()">🔍 Vyhledat</button>
        </div>
        
        <!-- Výpis tabulek jako čipy -->
        <div class="dbpr-table-list" id="dbpr_table_chips"></div>
    </div>

    <!-- Druhý panel: Data tabulky -->
    <div class="dbpr-panel" id="dbpr_data_panel" style="display: none;">
        <div class="flex-between" style="margin-bottom: 1rem;">
            <h3 id="dbpr_current_table"></h3>
            <div>
                <button class="btn btn-outline btn-sm" onclick="DBPR.showQueryBuilder()">⚡ Query Builder</button>
                <button class="btn btn-outline btn-sm" onclick="DBPR.refreshData()">🔄 Obnovit</button>
            </div>
        </div>
        
        <!-- Formulář pro přidání nového řádku -->
        <div class="dbpr-row-form" id="dbpr_row_form"></div>
        
        <!-- Tabulka s daty -->
        <div class="dbpr-data-table">
            <div style="overflow-x: auto;">
                <table id="dbpr_data_table">
                    <thead id="dbpr_table_head"></thead>
                    <tbody id="dbpr_table_body"></tbody>
                </table>
            </div>
        </div>
        
        <!-- Paginace -->
        <div class="dbpr-pagination" id="dbpr_pagination"></div>
    </div>

    <!-- Třetí panel: Statistické databáze a logy -->
    <div class="dbpr-panel">
        <h3>📊 Systémové zdroje</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem;">
            <div>
                <strong>🗄️ Dočasné soubory</strong>
                <div id="dbpr_tmp_files" class="text-sm text-dim"></div>
            </div>
            <div>
                <strong>📈 Statistiky samooptimalizace</strong>
                <div id="dbpr_stats_files" class="text-sm text-dim"></div>
            </div>
            <div>
                <strong>📝 Centrální logy</strong>
                <div id="dbpr_central_logs" class="text-sm text-dim"></div>
            </div>
        </div>
    </div>
</div>

<!-- Modální okno -->
<div id="dbpr_modal" style="display: none;" class="dbpr-modal-bg" onclick="if(event.target===this) DBPR.closeModal()">
    <div class="dbpr-modal">
        <div class="dbpr-modal-header">
            <h3 id="dbpr_modal_title">Upravit</h3>
            <button class="btn btn-outline btn-sm" onclick="DBPR.closeModal()">✕</button>
        </div>
        <div class="dbpr-modal-body" id="dbpr_modal_body"></div>
        <div class="dbpr-modal-footer">
            <button class="btn btn-outline btn-sm" onclick="DBPR.closeModal()">Zrušit</button>
            <button class="btn" id="dbpr_modal_save">💾 Uložit</button>
        </div>
    </div>
</div>

<?php $html = ob_get_clean(); ?>

<?php $js = <<<'JSEOF'
window.DBPR = {
    currentTable: null,
    currentPage: 1,
    totalPages: 1,
    columns: [],
    columnSearch: '',
    valueSearch: '',
    
    // Inicializace
    async init() {
        await this.loadTables();
        //await this.loadSystemResources();
        this.setupAutocomplete();
    },
    
    // Načtení seznamu tabulek
    async loadTables() {
        try {
            const data = await PC.api('dbpr', 'db_list_tables');
			
            document.getElementById('dbpr_tmp_files').innerHTML = (data.docasne_soubory || []).map(f => `<div>📄 ${f}</div>`).join('') || '<div class="text-dim">Žádné</div>';
            document.getElementById('dbpr_stats_files').innerHTML = (data.statistiky_samooptimalizace || []).map(f => `<div>📈 ${f}</div>`).join('') || '<div class="text-dim">Žádné</div>';
            document.getElementById('dbpr_central_logs').innerHTML = (data.centralni_logy || []).map(l => `<div>📝 ${l.jmeno} (${this.formatBytes(l.velikost_bytes)})</div>`).join('') || '<div class="text-dim">Žádné</div>';
			
            const tables = data.tabulky || {};
            const select = document.getElementById('dbpr_table_select');
            const chips = document.getElementById('dbpr_table_chips');
            
            select.innerHTML = '<option value="">-- Vyberte tabulku --</option>';
            chips.innerHTML = '';
            
            Object.keys(tables).sort().forEach(name => {
                const opt = document.createElement('option');
                opt.value = name;
                opt.textContent = `${name} (${tables[name].segmenty} segmentů, ${this.formatBytes(tables[name].velikost_bytes)})`;
                select.appendChild(opt);
                
                const chip = document.createElement('div');
                chip.className = 'dbpr-table-chip';
                chip.textContent = name;
                chip.onclick = () => {
                    select.value = name;
					this.loadColumns();
                    this.loadTableData();
                };
                chips.appendChild(chip);
            });
        } catch (e) {
            PC.toast('Chyba načtení tabulek: ' + e.message, 'err');
        }
    },
	
	    async loadColumns() { 
        const curable = document.getElementById('dbpr_table_select').value;
        try {
            const data = await PC.api('dbpr', 'db_get_data', { table: curable, page: 1, limit: 1 });
                const sloupec= Object.keys(data.records[0]).filter(k => !k.startsWith('_'));
            const selec = document.getElementById('dbpr_col_input');
            selec.innerHTML = '<option value="">-- Vyberte sloupec --</option>';
			sloupec.sort().forEach(name => {
                const opts = document.createElement('option');
                opts.value = name;
                opts.textContent = name;
                selec.appendChild(opts);
            });
			
        } catch (e) {
            PC.toast('Chyba načtení sloupců: ' + e.message, 'err');
		}
    },
    
    // Načtení systémových zdrojů
    async loadSystemResources() {
        try {
            const data = await PC.api('dbpr', 'db_list_tables');
            document.getElementById('dbpr_tmp_files').innerHTML = (data.docasne_soubory || []).map(f => `<div>📄 ${f}</div>`).join('') || '<div class="text-dim">Žádné</div>';
            document.getElementById('dbpr_stats_files').innerHTML = (data.statistiky_samooptimalizace || []).map(f => `<div>📈 ${f}</div>`).join('') || '<div class="text-dim">Žádné</div>';
            document.getElementById('dbpr_central_logs').innerHTML = (data.centralni_logy || []).map(l => `<div>📝 ${l.jmeno} (${this.formatBytes(l.velikost_bytes)})</div>`).join('') || '<div class="text-dim">Žádné</div>';
        } catch (e) {
            console.error('Chyba zdrojů:', e);
        }
    },
    
    // Načtení dat tabulky
    async loadTableData() {
        this.currentTable = document.getElementById('dbpr_table_select').value;
        this.currentPage = 1;
        this.columnSearch = document.getElementById('dbpr_col_input').value;
        this.valueSearch = document.getElementById('dbpr_val_input').value;
        
        if (!this.currentTable) {
            document.getElementById('dbpr_data_panel').style.display = 'none';
            return;
        }
        
        document.getElementById('dbpr_data_panel').style.display = 'block';
        document.getElementById('dbpr_current_table').innerHTML = `📊 ${this.currentTable}`;
        
        await this.fetchData();
        //await this.loadColumnsForAutocomplete();
    },
    
    async fetchData() {
        try {
            const limit = parseInt(document.getElementById('dbpr_limit').value);
            const data = await PC.api('dbpr', 'db_get_search', {
                table: this.currentTable,
                page: this.currentPage,
                limit: limit,
                search_col: this.columnSearch,
                search_val: this.valueSearch
            });
            
            this.totalPages = data.stranek;
            this.renderTable(data.records, data.celkem);
            this.renderPagination();
            this.renderAddRowForm();
        } catch (e) {
            PC.toast('Chyba načtení dat: ' + e.message, 'err');
        }
    },
    
    renderTable(records, total) {
        const thead = document.getElementById('dbpr_table_head');
        const tbody = document.getElementById('dbpr_table_body');
        
        if (!records || records.length === 0) {
            thead.innerHTML = '';
            tbody.innerHTML = '<tr><td colspan="100" class="text-dim" style="text-align:center;">Žádná data</td></tr>';
            return;
        }
        
        // Získání sloupců z prvního záznamu
        this.columns = Object.keys(records[0]).filter(k => !k.startsWith('_'));
        
        // Hlavička
        thead.innerHTML = '<tr>' + this.columns.map(col => `<th onclick="DBPR.sortByColumn('${col}')">${_esc(col)} ↕</th>`).join('') + '<th>Akce</th></tr>';
        
        // Tělo
        tbody.innerHTML = '';
        records.forEach(record => {
const row = document.createElement('tr');
this.columns.forEach(col => {
    const td = document.createElement('td');
    const val = record[col];
    const display = val === null || val === undefined ? '—' : String(val);
    
    td.className = 'dbpr-expandable';
    td.title = 'Klikněte pro úpravu nebo zobrazení historie';
    
    // Data vložíme do data- atributů a ošetříme Vaší funkcí _esc
    // V onclick předáme "this", což je odkaz na tento konkrétní kliknutý span
    td.innerHTML = `<span style="cursor:pointer;" 
        data-id="${_esc(record.id)}" 
        data-col="${_esc(col)}" 
        data-display="${_esc(display)}" 
        onclick="DBPR.openEditModal(this.dataset.id, this.dataset.col, this.dataset.display)"
    >${_esc(this.truncate(display, 50))}</span>`;
    
    row.appendChild(td);
});
            
            // Akce
            const actionTd = document.createElement('td');
            actionTd.innerHTML = `<button class="btn btn-outline btn-xs" onclick="DBPR.deleteRow('${_esc(String(record.id))}')" style="color:var(--danger)">🗑 Smazat</button>`;
            row.appendChild(actionTd);
            tbody.appendChild(row);
        });
        
        // Informace o počtu
		
        if (!document.getElementById('pocet')) {
        const info = document.createElement('div');
        info.id = 'pocet';
        info.className = 'text-sm text-dim';
        info.style.padding = '0.5rem';
        info.textContent = `Celkem záznamů: ${total}`;
        tbody.parentNode.parentNode.insertBefore(info, tbody.parentNode.nextSibling);
        } else {
        document.getElementById('pocet').textContent = `Celkem záznamů: ${total}`;
        } 
    },
    
    renderAddRowForm() {
        const container = document.getElementById('dbpr_row_form');
        if (!this.columns.length) {
            container.innerHTML = '';
            return;
        }
        
        container.innerHTML = '<strong class="text-sm">➕ Přidat nový řádek:</strong>';
        this.columns.forEach(col => {
            const input = document.createElement('input');
            input.type = 'text';
            input.placeholder = col;
            input.id = `dbpr_new_${col}`;
            input.className = 'input';
            input.style.fontSize = '0.75rem';
            container.appendChild(input);
        });
        
        const btn = document.createElement('button');
        btn.className = 'btn btn-sm';
        btn.innerHTML = '💾 Uložit jako nový řádek';
        btn.onclick = () => this.insertRow();
        container.appendChild(btn);
    },
    
    async insertRow() {
        const data = {};
        for (const col of this.columns) {
            const val = document.getElementById(`dbpr_new_${col}`)?.value;
            if (val) data[col] = val;
        }
        
        if (Object.keys(data).length === 0) {
            PC.toast('Vyplňte alespoň jednu hodnotu', 'warn');
            return;
        }
        
        try {
            await PC.api('dbpr', 'db_insert_row', { table: this.currentTable, data });
            PC.toast('Řádek přidán', 'ok');
            this.fetchData();
            // Vyčištění formuláře
            this.columns.forEach(col => {
                const inp = document.getElementById(`dbpr_new_${col}`);
                if (inp) inp.value = '';
            });
        } catch (e) {
            PC.toast('Chyba přidání: ' + e.message, 'err');
        }
    },
    
    renderPagination() {
        const container = document.getElementById('dbpr_pagination');
        if (this.totalPages <= 1) {
            container.innerHTML = '';
            return;
        }
        
        let html = `<button class="btn btn-outline btn-sm" onclick="DBPR.goToPage(1)" ${this.currentPage === 1 ? 'disabled' : ''}>⏮</button>`;
        html += `<button class="btn btn-outline btn-sm" onclick="DBPR.goToPage(${this.currentPage - 1})" ${this.currentPage === 1 ? 'disabled' : ''}>◀</button>`;
        html += `<span class="text-sm">Stránka ${this.currentPage} z ${this.totalPages}</span>`;
        html += `<button class="btn btn-outline btn-sm" onclick="DBPR.goToPage(${this.currentPage + 1})" ${this.currentPage === this.totalPages ? 'disabled' : ''}>▶</button>`;
        html += `<button class="btn btn-outline btn-sm" onclick="DBPR.goToPage(${this.totalPages})" ${this.currentPage === this.totalPages ? 'disabled' : ''}>⏭</button>`;
        container.innerHTML = html;
    },
    
    goToPage(page) {
        this.currentPage = page;
        this.fetchData();
    },
    
    refreshData() {
        this.fetchData();
    },
    
    sortByColumn(col) {
        PC.toast('Řazení zatím není implementováno, použijte vyhledávání', 'warn');
    },
    
    // Autocomplete pro sloupce a hodnoty
    async setupAutocomplete() {
/*         const colInput = document.getElementById('dbpr_col_input');
        const colResults = document.getElementById('dbpr_col_results'); */
        const valInput = document.getElementById('dbpr_val_input');
        const valResults = document.getElementById('dbpr_val_results');
        
        let colTimeout, valTimeout;
        
/*         colInput.addEventListener('input', () => {
            clearTimeout(colTimeout);
            colTimeout = setTimeout(() => this.searchColumns(colInput.value, colResults), 300);
        }); */
        
        valInput.addEventListener('input', () => {
            clearTimeout(valTimeout);
            valTimeout = setTimeout(() => this.searchValues(valInput.value, valResults), 300);
        });
        
        // Zavření při kliknutí mimo
        document.addEventListener('click', (e) => {
/*             if (!colInput.contains(e.target) && !colResults.contains(e.target)) colResults.classList.remove('show'); */
            if (!valInput.contains(e.target) && !valResults.contains(e.target)) valResults.classList.remove('show');
        });
    },
    
    async loadColumnsForAutocomplete() {
        if (!this.currentTable) return;
        try {
            const data = await PC.api('dbpr', 'db_get_data', { table: this.currentTable, page: 1, limit: 1 });
            if (data.records && data.records[0]) {
                this.columns = Object.keys(data.records[0]).filter(k => !k.startsWith('_'));
            }
        } catch (e) {}
    },
    
    async searchColumns(query, resultsDiv) {
        if (query.length < 2 || !this.columns.length) {
            resultsDiv.classList.remove('show');
            return;
        }
        
        const matches = this.columns.filter(c => c.toLowerCase().includes(query.toLowerCase())).slice(0, 10);
        if (matches.length === 0) {
            resultsDiv.classList.remove('show');
            return;
        }
        
        resultsDiv.innerHTML = matches.map(m => `<div class="dbpr-autocomplete-item" onclick="DBPR.selectColumn('${_esc(m)}')">${_esc(m)}</div>`).join('');
        resultsDiv.classList.add('show');
    },
    
    async searchValues(query, resultsDiv) {
        if (query.length < 2 || !this.currentTable || !this.columnSearch) {
            resultsDiv.classList.remove('show');
            return;
        }
        
        try {
            const data = await PC.api('dbpr', 'db_get_search', {
                table: this.currentTable,
                page: 1,
                limit: 20,
                search_col: this.columnSearch,
                search_val: query
            });
            
             const values = [...new Set((data.records || []).map(r => r[this.columnSearch]).filter(v => v && String(v).toLowerCase().includes(query.toLowerCase())))].slice(0, 10);
/*            
			// Opravená vyhledávací funkce na frontendu
const values = [
  ...new Set(
    (data.records || [])
      .flatMap(r => {
        const val = r[this.columnSearch]; // Tady je to pole ["input", "dbpr"] z PHP
        return Array.isArray(val) ? val : [val]; // Rozbalí pole na samostatné položky
      })
      // Nyní se filtruje 'input' a 'dbpr' jako dva samostatné texty, ne dohromady s čárkou
      .filter(v => v && String(v).toLowerCase().includes(query.toLowerCase()))
  )
].slice(0, 10);
 */
			
			
            if (values.length === 0) {
                resultsDiv.classList.remove('show');
                return;
            }
			
			
/*             resultsDiv.innerHTML = values.map(v => `<div class="dbpr-autocomplete-item" onclick="DBPR.selectValue('${_esc(JSON.stringify(v))}')">${this.truncate(String(v), 50)}</div>`).join(''); */
            
            resultsDiv.innerHTML = values.map(v => `<div class="dbpr-autocomplete-item" onclick="DBPR.selectValue('${_esc(v)}')">${this.truncate(String(v), 50)}</div>`).join('');
            resultsDiv.classList.add('show');
        } catch (e) {
            resultsDiv.classList.remove('show');
        }
    },
    
    selectColumn(col) {
        document.getElementById('dbpr_col_input').value = col;
        this.columnSearch = col;
        document.getElementById('dbpr_col_results').classList.remove('show');
        this.loadTableData();
    },
    
    selectValue(val) {
        document.getElementById('dbpr_val_input').value = val;
        this.valueSearch = val;
        document.getElementById('dbpr_val_results').classList.remove('show');
        this.loadTableData();
    },
    
    // Editace hodnoty nebo sloupce
    async openEditModal(recordId, column, currentValue) {
        this.editRecordId = recordId;
        this.editColumn = column;
        
        const modal = document.getElementById('dbpr_modal');
        const title = document.getElementById('dbpr_modal_title');
        const body = document.getElementById('dbpr_modal_body');
        const saveBtn = document.getElementById('dbpr_modal_save');
        
        title.innerHTML = `✏️ Úprava: ${column}`;
        
        // Načtení historie hodnoty
        let historyHtml = '';
        try {
            const history = await PC.api('dbpr', 'db_get_history', { table: this.currentTable, id: recordId });
            if (history.verze && history.verze.length > 0) {
                historyHtml = '<div style="margin-top: 1rem;"><strong>📜 Historie hodnot (aktuální + 4 předchozí):</strong><div style="margin-top: 0.5rem;">';
                history.verze.forEach((ver, idx) => {
                    const val = ver[column] || '—';
                    historyHtml += `<div class="dbpr-history-item">
                        <div><small class="text-dim">Verze ${idx + 1}</small><br>${this.truncate(String(val), 100)}</div>
                        <button class="btn btn-outline btn-xs" onclick="DBPR.restoreVersion('${recordId}', '${column}', ${idx})">↩ Aktivovat</button>
                    </div>`;
                });
                historyHtml += '</div></div>';
            }
        } catch (e) {}
        
        // Načtení propojení (pokud existují)
        let linkedHtml = '';
        try {
            const linked = await PC.api('dbpr', 'db_get_linked', { table: this.currentTable, column: column, value: currentValue });
            if (linked && linked.length) {
                linkedHtml = '<div style="margin-top: 1rem;"><strong>🔗 Propojeno s:</strong><div style="margin-top: 0.5rem;">';
                linked.forEach(l => {
                    linkedHtml += `<div class="dbpr-linked-item">
                        <span>${l.table}.${l.column} → ${this.truncate(l.value, 50)}</span>
                        <button class="btn btn-outline btn-xs" onclick="DBPR.unlink('${l.id}')">Odpojit</button>
                    </div>`;
                });
                linkedHtml += '</div></div>';
            }
        } catch (e) {}
        
        body.innerHTML = `
            <div class="form-group">
                <label>Nová hodnota pro sloupec <strong>${_esc(column)}</strong></label>
                <textarea id="dbpr_edit_value" class="input" rows="3">${_esc(currentValue)}</textarea>
                <small class="text-dim">Po změně se automaticky vytvoří nová verze (max 5 verzí).</small>
            </div>
            <div class="form-group">
                <label>✏️ Přejmenovat sloupec (volitelné)</label>
                <input type="text" id="dbpr_rename_column" class="input" placeholder="Nový název sloupce">
            </div>
            ${historyHtml}
            ${linkedHtml}
        `;
        
        saveBtn.onclick = () => this.saveEdit(recordId, column);
        modal.style.display = 'flex';
    },
    
    async saveEdit(recordId, column) {
        const newValue = document.getElementById('dbpr_edit_value')?.value;
        const renameTo = document.getElementById('dbpr_rename_column')?.value;
        
        if (newValue !== undefined) {
            try {
                await PC.api('dbpr', 'db_update_value', {
                    table: this.currentTable,
                    id: recordId,
                    column: column,
                    value: newValue
                });
                PC.toast('Hodnota uložena', 'ok');
            } catch (e) {
                PC.toast('Chyba uložení: ' + e.message, 'err');
            }
        }
        
        if (renameTo && renameTo !== column) {
            try {
                await PC.api('dbpr', 'db_rename_column', {
                    table: this.currentTable,
                    column: column,
                    new_name: renameTo
                });
                PC.toast(`Sloupec přejmenován na ${renameTo}`, 'ok');
                await this.loadColumnsForAutocomplete();
            } catch (e) {
                PC.toast('Chyba přejmenování: ' + e.message, 'err');
            }
        }
        
        this.closeModal();
        this.fetchData();
    },
    
    async restoreVersion(recordId, column, versionIndex) {
        try {
            await PC.api('dbpr', 'db_rollback_version', {
                table: this.currentTable,
                id: recordId,
                version_index: versionIndex
            });
            PC.toast('Obnovena starší verze', 'ok');
            this.closeModal();
            this.fetchData();
        } catch (e) {
            PC.toast('Chyba obnovy: ' + e.message, 'err');
        }
    },
    
    async deleteRow(recordId) {
        if (!confirm('Opravdu smazat tento řádek? Data budou označena jako smazaná a zůstanou v historii.')) return;
        try {
            await PC.api('dbpr', 'db_delete_row', { table: this.currentTable, id: recordId });
            PC.toast('Řádek smazán', 'ok');
            this.fetchData();
        } catch (e) {
            PC.toast('Chyba mazání: ' + e.message, 'err');
        }
    },
    
    // Query Builder
    showQueryBuilder() {
        const modal = document.getElementById('dbpr_modal');
        const title = document.getElementById('dbpr_modal_title');
        const body = document.getElementById('dbpr_modal_body');
        const saveBtn = document.getElementById('dbpr_modal_save');
        
        title.innerHTML = '⚡ Query Builder';
        
        body.innerHTML = `
            <div class="dbpr-query-builder">
                <div class="dbpr-query-steps">
                    <div class="form-group">
                        <label>Akce</label>
                        <select id="qb_action" class="input" onchange="DBPR.updateQueryPreview()">
                            <option value="select">SELECT (čtení)</option>
                            <option value="insert">INSERT (vložení)</option>
                            <option value="update">UPDATE (aktualizace)</option>
                            <option value="delete">DELETE (smazání)</option>
                            <option value="schema">SCHEMA (změna struktury)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Tabulka</label>
                        <div class="dbpr-autocomplete" style="position:relative;">
                            <input type="text" id="qb_table" class="input dbpr-autocomplete-input" placeholder="Název tabulky" autocomplete="off">
                            <div class="dbpr-autocomplete-results" id="qb_table_results"></div>
                        </div>
                    </div>
                </div>
                <div id="qb_params_container"></div>
                <div class="dbpr-query-preview">
                    <strong>📝 PHP kód:</strong>
                    <pre id="qb_preview" style="margin-top: 0.5rem; overflow-x: auto;"></pre>
                </div>
                <div style="display: flex; gap: 0.5rem;">
                    <button class="btn btn-outline btn-sm" onclick="DBPR.copyQuery()">📋 Kopírovat</button>
                    <button class="btn btn-sm" onclick="DBPR.executeQuery()">▶ Provést</button>
                </div>
                <div id="qb_result" style="margin-top: 1rem; display: none;">
                    <strong>✅ Výsledek:</strong>
                    <pre id="qb_result_content" style="background: var(--bg); border-radius: 8px; padding: 0.5rem; font-size: 0.7rem; overflow-x: auto;"></pre>
                </div>
            </div>
        `;
        
        // Nastavení autocomplete pro tabulky
        const tableInput = document.getElementById('qb_table');
        const tableResults = document.getElementById('qb_table_results');
        
        tableInput.addEventListener('input', async () => {
            const val = tableInput.value;
            if (val.length < 2) {
                tableResults.classList.remove('show');
                return;
            }
            try {
                const data = await PC.api('dbpr', 'db_list_tables');
                const tables = Object.keys(data.tabulky || {}).filter(t => t.toLowerCase().includes(val.toLowerCase())).slice(0, 10);
                if (tables.length) {
                    tableResults.innerHTML = tables.map(t => `<div class="dbpr-autocomplete-item" onclick="DBPR.selectQueryTable('${_esc(t)}')">${_esc(t)}</div>`).join('');
                    tableResults.classList.add('show');
                } else {
                    tableResults.classList.remove('show');
                }
            } catch (e) {}
        });
        
        document.addEventListener('click', (e) => {
            if (!tableInput.contains(e.target) && !tableResults.contains(e.target)) tableResults.classList.remove('show');
        });
        
        saveBtn.onclick = () => this.executeQuery();
        saveBtn.innerHTML = '▶ Provést';
        this.updateQueryPreview();
        modal.style.display = 'flex';
    },
    
    selectQueryTable(table) {
        document.getElementById('qb_table').value = table;
        document.getElementById('qb_table_results').classList.remove('show');
        this.updateQueryPreview();
    },
    
    async updateQueryPreview() {
        const action = document.getElementById('qb_action')?.value;
        const table = document.getElementById('qb_table')?.value;
        const paramsDiv = document.getElementById('qb_params_container');
        
        if (!action || !table) {
            document.getElementById('qb_preview').textContent = 'Vyberte akci a tabulku';
            return;
        }
        
        // Dynamické načtení parametrů podle akce
        if (action === 'select') {
            paramsDiv.innerHTML = `
                <div class="form-group">
                    <label>Filtrování (JSON, např. {"where": {"sloupec": "hodnota"}})</label>
                    <textarea id="qb_params" class="input monospace" rows="3" placeholder='{"where": {"status": "aktivni"}}'></textarea>
                </div>
            `;
        } else if (action === 'insert') {
            paramsDiv.innerHTML = `
                <div class="form-group">
                    <label>Data (JSON objekt)</label>
                    <textarea id="qb_params" class="input monospace" rows="3" placeholder='{"nazev": "Novy zaznam", "status": "novy"}'></textarea>
                </div>
            `;
        } else if (action === 'update') {
            paramsDiv.innerHTML = `
                <div class="form-group">
                    <label>ID záznamu</label>
                    <input type="text" id="qb_update_id" class="input" placeholder="např. U1234">
                </div>
                <div class="form-group">
                    <label>Data k aktualizaci (JSON)</label>
                    <textarea id="qb_params" class="input monospace" rows="3" placeholder='{"status": "zmeneno"}'></textarea>
                </div>
            `;
        } else if (action === 'delete') {
            paramsDiv.innerHTML = `
                <div class="form-group">
                    <label>ID záznamu ke smazání</label>
                    <input type="text" id="qb_delete_id" class="input" placeholder="např. U1234">
                </div>
            `;
        } else if (action === 'schema') {
            paramsDiv.innerHTML = `
                <div class="form-group">
                    <label>Úloha schématu</label>
                    <select id="qb_schema_task" class="input" onchange="DBPR.updateQueryPreview()">
                        <option value="create_column">Vytvořit sloupec</option>
                        <option value="rename_column">Přejmenovat sloupec</option>
                        <option value="link_columns">Propojit sloupce</option>
                    </select>
                </div>
                <div id="qb_schema_params"></div>
            `;
            this.updateSchemaParams();
        }
        
        // Generování PHP kódu
        let phpCode = `DB::getInstance()->run('${table}', '${action}', [`;
        if (action === 'select') {
            const params = document.getElementById('qb_params')?.value;
            if (params && params.trim()) phpCode += `\n    ${params}`;
        } else if (action === 'insert') {
            const data = document.getElementById('qb_params')?.value;
            if (data && data.trim()) phpCode += `\n    ${data}`;
        } else if (action === 'update') {
            const id = document.getElementById('qb_update_id')?.value;
            const data = document.getElementById('qb_params')?.value;
            if (id) phpCode += `\n    'id' => '${id}',`;
            if (data && data.trim()) phpCode += `\n    'data' => ${data}`;
        } else if (action === 'delete') {
            const id = document.getElementById('qb_delete_id')?.value;
            if (id) phpCode += `\n    'id' => '${id}'`;
        } else if (action === 'schema') {
            const task = document.getElementById('qb_schema_task')?.value;
            phpCode += `\n    'task' => '${task}'`;
            if (task === 'create_column') {
                const col = document.getElementById('qb_schema_col')?.value;
                const def = document.getElementById('qb_schema_default')?.value;
                if (col) phpCode += `,\n    'column' => '${col}'`;
                if (def) phpCode += `,\n    'default_value' => '${def}'`;
            } else if (task === 'rename_column') {
                const old = document.getElementById('qb_schema_old')?.value;
                const newName = document.getElementById('qb_schema_new')?.value;
                if (old) phpCode += `,\n    'column' => '${old}'`;
                if (newName) phpCode += `,\n    'new_name' => '${newName}'`;
            } else if (task === 'link_columns') {
                const col = document.getElementById('qb_schema_col')?.value;
                const target = document.getElementById('qb_schema_target')?.value;
                const targetCol = document.getElementById('qb_schema_target_col')?.value;
                if (col) phpCode += `,\n    'column' => '${col}'`;
                if (target) phpCode += `,\n    'target_table' => '${target}'`;
                if (targetCol) phpCode += `,\n    'target_column' => '${targetCol}'`;
            }
        }
        phpCode += `\n]);`;
        
        document.getElementById('qb_preview').textContent = phpCode;
    },
    
    async updateSchemaParams() {
        const container = document.getElementById('qb_schema_params');
        const task = document.getElementById('qb_schema_task')?.value;
        
        if (task === 'create_column') {
            container.innerHTML = `
                <div class="form-group"><label>Název sloupce</label><input type="text" id="qb_schema_col" class="input" placeholder="např. nova_hodnota" oninput="DBPR.updateQueryPreview()"></div>
                <div class="form-group"><label>Výchozí hodnota</label><input type="text" id="qb_schema_default" class="input" placeholder="např. ''" oninput="DBPR.updateQueryPreview()"></div>
            `;
        } else if (task === 'rename_column') {
            container.innerHTML = `
                <div class="form-group"><label>Starý název</label><input type="text" id="qb_schema_old" class="input" placeholder="původní_název" oninput="DBPR.updateQueryPreview()"></div>
                <div class="form-group"><label>Nový název</label><input type="text" id="qb_schema_new" class="input" placeholder="nový_název" oninput="DBPR.updateQueryPreview()"></div>
            `;
        } else if (task === 'link_columns') {
            container.innerHTML = `
                <div class="form-group"><label>Sloupec v této tabulce</label><input type="text" id="qb_schema_col" class="input" placeholder="sloupec" oninput="DBPR.updateQueryPreview()"></div>
                <div class="form-group"><label>Cílová tabulka</label><input type="text" id="qb_schema_target" class="input" placeholder="cilova_tabulka" oninput="DBPR.updateQueryPreview()"></div>
                <div class="form-group"><label>Cílový sloupec</label><input type="text" id="qb_schema_target_col" class="input" placeholder="cilovy_sloupec" oninput="DBPR.updateQueryPreview()"></div>
            `;
        }
    },
    
    copyQuery() {
        const code = document.getElementById('qb_preview')?.textContent;
        if (code) {
            navigator.clipboard.writeText(code);
            PC.toast('Kód zkopírován do schránky', 'ok');
        }
    },
    
    async executeQuery() {
        const action = document.getElementById('qb_action')?.value;
        const table = document.getElementById('qb_table')?.value;
        if (!table) {
            PC.toast('Vyberte tabulku', 'warn');
            return;
        }
        
        let params = {};
        if (action === 'select') {
            const filter = document.getElementById('qb_params')?.value;
            if (filter && filter.trim()) params = JSON.parse(filter);
        } else if (action === 'insert') {
            const data = document.getElementById('qb_params')?.value;
            if (data && data.trim()) params = { data: JSON.parse(data) };
        } else if (action === 'update') {
            const id = document.getElementById('qb_update_id')?.value;
            const data = document.getElementById('qb_params')?.value;
            if (id) params.id = id;
            if (data && data.trim()) params.data = JSON.parse(data);
        } else if (action === 'delete') {
            const id = document.getElementById('qb_delete_id')?.value;
            if (id) params.id = id;
        } else if (action === 'schema') {
            const task = document.getElementById('qb_schema_task')?.value;
            params.task = task;
            if (task === 'create_column') {
                params.column = document.getElementById('qb_schema_col')?.value;
                params.default_value = document.getElementById('qb_schema_default')?.value;
            } else if (task === 'rename_column') {
                params.column = document.getElementById('qb_schema_old')?.value;
                params.new_name = document.getElementById('qb_schema_new')?.value;
            } else if (task === 'link_columns') {
                params.column = document.getElementById('qb_schema_col')?.value;
                params.target_table = document.getElementById('qb_schema_target')?.value;
                params.target_column = document.getElementById('qb_schema_target_col')?.value;
            }
        }
        
        try {
            const result = await PC.api('dbpr', `db_${action}_query`, { table: table, ...params });
            const resultDiv = document.getElementById('qb_result');
            const resultContent = document.getElementById('qb_result_content');
            resultDiv.style.display = 'block';
            resultContent.textContent = JSON.stringify(result, null, 2);
            PC.toast('Příkaz proveden', 'ok');
        } catch (e) {
            PC.toast('Chyba provedení: ' + e.message, 'err');
            const resultDiv = document.getElementById('qb_result');
            const resultContent = document.getElementById('qb_result_content');
            resultDiv.style.display = 'block';
            resultContent.textContent = `CHYBA: ${e.message}`;
        }
    },
    
    closeModal() {
        document.getElementById('dbpr_modal').style.display = 'none';
    },
    
    formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    },
    
    truncate(str, maxLen) {
        if (!str) return '—';
        str = String(str);
        return str.length > maxLen ? str.substr(0, maxLen) + '…' : str;
    }
};

// Inicializace
//setTimeout(() => DBPR.init(), 100);

JSEOF;

return ['html' => $html, 'js' => $js . "\n\n"];