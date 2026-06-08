<?php
ob_start();
?>
<style>
/* Základní styly Stavebnice */
.bv4 { display:flex; flex-direction:column; height:calc(100vh - 90px); min-height:500px; border-radius:var(--radius); overflow:hidden; border:1px solid var(--border); background:var(--bg); position:relative; }
.bv4-toolbar { display:flex; gap:0.3rem; padding:0.4rem 0.75rem; background:var(--surface); border-bottom:1px solid var(--border); align-items:center; flex-wrap:wrap; }
.bv4-body { display:flex; flex:1; overflow:hidden; }
.bv4-sidebar { width:250px; min-width:200px; border-right:1px solid var(--border); display:flex; flex-direction:column; background:var(--bg); overflow:hidden; }
.bv4-tabs { display:flex; border-bottom:1px solid var(--border); }
.bv4-tab { flex:1; padding:0.4rem 0.3rem; font-size:0.75rem; text-align:center; cursor:pointer; border:none; background:none; color:var(--text-dim); border-bottom:2px solid transparent; transition:.15s; }
.bv4-tab.active { color:var(--primary); border-bottom-color:var(--primary); background:var(--surface); font-weight:600; }
.bv4-panel { flex:1; overflow-y:auto; padding:0.5rem; display:none; }
.bv4-panel.active { display:block; }
.bv4-search { width:100%; padding:0.35rem 0.6rem; border:1px solid var(--border); border-radius:6px; background:var(--bg); color:var(--text); font-size:0.78rem; margin-bottom:0.4rem; box-sizing:border-box; outline:none; }
.bv4-filters { display:flex; flex-wrap:wrap; gap:0.25rem; margin-bottom:0.4rem; }
.bv4-filter-btn { font-size:0.7rem; padding:2px 8px; border:1px solid var(--border); border-radius:20px; background:none; cursor:pointer; color:var(--text-dim); transition:.15s; }
.bv4-filter-btn.active { background:var(--primary); color:#fff; border-color:var(--primary); }
.bv4-item { display:flex; justify-content:space-between; align-items:center; padding:0.35rem 0.5rem; border:1px solid var(--border); border-radius:6px; margin-bottom:0.25rem; cursor:pointer; font-size:0.78rem; transition:.15s; }
.bv4-item:hover { border-color:var(--primary); background:var(--primary-dim); }
.bv4-canvas { flex:1; padding:0.75rem; overflow-y:auto; background:var(--surface); position:relative; }
.bv4-empty { display:flex; align-items:center; justify-content:center; height:100%; color:var(--text-dim); font-size:0.85rem; pointer-events:none; flex-direction:column; gap:0.5rem; }
.bv4-node { position:relative; border:2px dashed transparent; border-radius:8px; margin-bottom:0.4rem; padding:0.4rem; transition:.15s; cursor:grab; }
.bv4-node:hover { border-color:var(--primary); }
.bv4-node-ctrl { position:absolute; top:-18px; right:4px; display:none; background:var(--surface); border:1px solid var(--border); border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,.3); overflow:hidden; z-index:20; white-space:nowrap; }
.bv4-node:hover .bv4-node-ctrl { display:flex; }
.bv4-nc { background:none; border:none; padding:5px 8px; cursor:pointer; font-size:11px; color:var(--text); transition:.1s; }
.bv4-nc:hover { background:var(--primary-dim); color:var(--primary); }
.bv4-synapse-badge { display:inline-block; font-size:0.62rem; padding:1px 6px; border-radius:10px; background:rgba(33,150,243,.15); color:#2196F3; margin-top:3px; }
.bv4-inspector { width:0; overflow:hidden; border-left:1px solid var(--border); background:var(--bg); transition:width .2s; display:flex; flex-direction:column; }
.bv4-inspector.open { width:320px; min-width:260px; }
.bv4-insp-head { padding:0.5rem 0.75rem; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; background:var(--surface); }
.bv4-insp-body { padding:0.6rem 0.75rem; overflow-y:auto; flex:1; font-size:0.8rem; }
.bv4-insp-tabs { display:flex; gap:0.25rem; margin-bottom:0.5rem; }
.bv4-insp-tab { flex:1; padding:3px; font-size:0.72rem; border:1px solid var(--border); border-radius:6px; cursor:pointer; background:none; color:var(--text-dim); text-align:center; transition:.15s; }
.bv4-insp-tab.active { background:var(--primary); color:#fff; border-color:var(--primary); }

/* Sandbox */
.bv4-sandbox { position:fixed; bottom:0; left:0; right:0; height:0; background:var(--surface); border-top:2px solid var(--primary); transition:height .3s; z-index:500; overflow:hidden; display:flex; flex-direction:column; }
.bv4-sandbox.open { height:320px; }
.bv4-sb-head { display:flex; justify-content:space-between; align-items:center; padding:0.5rem 1rem; border-bottom:1px solid var(--border); background:var(--bg); }
.bv4-sb-body { display:flex; flex:1; overflow:hidden; }
.bv4-sb-left { flex:1; padding:0.75rem; overflow-y:auto; border-right:1px solid var(--border); }
.bv4-sb-right { flex:1; padding:0.75rem; overflow-y:auto; }
.bv4-sb-result { background:var(--bg); border:1px solid var(--border); border-radius:8px; padding:0.75rem; font-family:monospace; font-size:0.75rem; white-space:pre-wrap; max-height:200px; overflow-y:auto; }

/* Typografie & Odznaky */
.typ-badge { font-size:0.62rem; padding:1px 6px; border-radius:4px; font-weight:700; }
.typ-badge-api { background:rgba(76,175,80,.2); color:#4CAF50; }
.typ-badge-js { background:rgba(33,150,243,.2); color:#2196F3; }
.typ-badge-modul { background:rgba(255,152,0,.2); color:#FF9800; }
.typ-badge-css_var { background:rgba(156,39,176,.2); color:#9C27B0; }
.typ-badge-abstraktni { background:rgba(96,125,139,.2); color:#607D8B; }
.typ-badge-db_tabulka { background:rgba(244,67,54,.2); color:#f44336; }
.typ-badge-html_komponenta { background:rgba(233,30,99,.2); color:#E91E63; }

/* Modal */
.bv4-modal-bg { position:fixed; inset:0; background:rgba(0,0,0,.75); z-index:8000; display:flex; align-items:center; justify-content:center; padding:1rem; backdrop-filter:blur(2px); }
.bv4-modal { background:var(--surface); border:1px solid var(--border); border-radius:12px; box-shadow:0 12px 40px rgba(0,0,0,.5); max-height:88vh; overflow-y:auto; width:600px; max-width:100%; padding:1.25rem; }
.bv4-modal-foot { display:flex; gap:0.5rem; justify-content:flex-end; margin-top:1rem; padding-top:0.75rem; border-top:1px solid var(--border); }

/* Vizuální mapa Overlay */
#bv4_map_overlay { display:none; position:absolute; inset:0; background:var(--bg); z-index:9000; flex-direction:column; }
.map-canvas-area { flex:1; background:#050705; cursor:grab; position:relative; overflow:hidden; }
.map-canvas-area:active { cursor:grabbing; }
.map-controls { position:absolute; bottom:20px; right:20px; display:flex; flex-direction:column; gap:8px; }
.map-controls button { width:40px; height:40px; border-radius:8px; border:none; background:var(--surface); color:var(--text); font-size:1.5rem; cursor:pointer; box-shadow:0 4px 12px rgba(0,0,0,.4); display:flex; align-items:center; justify-content:center; transition:0.1s; }
.map-controls button:hover { background:var(--primary); color:#fff; }
</style>

<div class="bv4" id="bv4">
  <div class="bv4-toolbar">
    <strong style="font-size:0.8rem; color:var(--primary);">⚙️ Stavebnice</strong>
    <button class="btn btn-outline btn-xs" onclick="BV4.history.undo()" title="Zpět (Ctrl+Z)">↩</button>
    <button class="btn btn-outline btn-xs" onclick="BV4.history.redo()" title="Vpřed (Ctrl+Y)">↪</button>
    <button class="btn btn-outline btn-xs" onclick="BV4.canvas.clear()" style="color:var(--danger)" title="Vyčistit">🗑</button>
    <div style="flex:1;"></div>
    <span id="bv4_proj_name" class="text-dim text-xs">— nový projekt —</span>
    <button class="btn btn-outline btn-xs" onclick="BV4.sandbox.toggle()" title="Virtuální testovací prostor">🧪 Sandbox</button>
    <button class="btn btn-outline btn-xs" onclick="BV4.map.show()" title="Neurální Mapa Systému" style="color:var(--accent);">🗺️ Mapa</button>
    <button class="btn btn-outline btn-xs" onclick="BV4.history.showModal()" title="Historie změn">📜 Historie</button>
    <button class="btn btn-outline btn-xs" onclick="BV4.inspector.toggle()" title="Inspektor">🔬</button>
    <button class="btn btn-outline btn-xs" onclick="BV4.projects.showModal()" title="Projekty">📂</button>
    <button class="btn btn-xs" onclick="BV4.projects.save()">💾 Uložit</button>
    <button class="btn btn-xs" style="background:var(--warning); color:#fff; border:none; margin-left:10px;" onclick="BV4.deployPlatform()" title="Sestaví fyzické .php soubory z fragmentů uložených v DB">🚀 Sestavit platformu z DB</button>
  </div>

  <div class="bv4-body">
    <div class="bv4-sidebar">
      <div class="bv4-tabs">
        <button class="bv4-tab active" id="tab_lib" onclick="BV4.sidebar.show('lib')">📦 Knihovna</button>
        <button class="bv4-tab" id="tab_syn" onclick="BV4.sidebar.show('syn')">🕸 Synapse</button>
      </div>
      
      <div class="bv4-panel active" id="panel_lib">
        <input class="bv4-search" type="text" id="lib_q" placeholder="Hledat komponentu..." oninput="BV4.components.filter(this.value)">
        <div style="display:flex; gap:0.25rem; margin-bottom:0.4rem;">
          <button class="btn btn-outline btn-xs" style="flex:1" onclick="BV4.components.edit()">+ Nová</button>
          <button class="btn btn-outline btn-xs" style="flex:1" onclick="BV4.components.scan()">🔍 Sken HTML</button>
        </div>
        <div id="b_library"><div class="text-dim text-sm" style="text-align:center;padding:.5rem">Načítám…</div></div>
      </div>

      <div class="bv4-panel" id="panel_syn">
        <input class="bv4-search" type="text" id="syn_q" placeholder="Hledat uzel..." oninput="BV4.synapse.search(this.value)">
        <div class="bv4-filters" id="syn_filters">
          <button class="bv4-filter-btn active" onclick="BV4.synapse.setFilter('skupina','')" id="sf_all">Vše</button>
          <button class="bv4-filter-btn" onclick="BV4.synapse.setFilter('skupina','fyzicka')" id="sf_fyz">Fyzické</button>
          <button class="bv4-filter-btn" onclick="BV4.synapse.setFilter('skupina','abstraktni')" id="sf_abs">Abstraktní</button>
        </div>
        <select class="bv4-search" id="syn_typ_filter" onchange="BV4.synapse.setFilter('typ',this.value)" style="margin-bottom:0.4rem;">
            <option value="">— Všechny typy —</option>
            <option value="api">API Endopointy</option>
            <option value="js">JavaScript Receptory</option>
            <option value="html_komponenta">HTML Komponenty</option>
            <option value="modul">Moduly</option>
            <option value="db_tabulka">DB Tabulky</option>
        </select>
        <div id="syn_list"><div class="text-dim text-sm" style="text-align:center;padding:.5rem">Načítám uzly…</div></div>
      </div>
    </div>

    <div class="bv4-canvas" id="b_canvas" ondragover="BV4.dnd.onDragOver(event)" ondrop="BV4.dnd.onDrop(event)">
      <div class="bv4-empty" id="bv4_empty">
        <span>🏗️</span>
        <span>Přetáhněte komponentu nebo klikněte ➕</span>
      </div>
    </div>

    <div class="bv4-inspector" id="bv4_inspector">
      <div class="bv4-insp-head">
        <strong style="font-size:0.82rem;">🔬 Inspektor</strong>
        <button class="bv4-nc" onclick="BV4.inspector.toggle()" style="padding:2px 6px;">✕</button>
      </div>
      <div class="bv4-insp-body" id="bv4_insp_content">
        <div class="text-dim text-sm">Zvolte uzel ke zkoumání.</div>
      </div>
    </div>
  </div>

  <div id="bv4_map_overlay">
    <div class="bv4-toolbar" style="border-bottom:1px solid var(--border); padding:0.5rem 1rem;">
      <h3 style="margin:0; color:var(--accent); font-size:1.1rem; flex:1;">🗺️ Neurální Mapa Systému</h3>
      <button class="btn btn-outline btn-xs" onclick="BV4.map.resetView()">Centrovat zobrazení</button>
      <button class="btn btn-xs" style="background:var(--danger);" onclick="document.getElementById('bv4_map_overlay').style.display='none'">✕ Zavřít mapu</button>
    </div>
    <div style="display:flex; flex:1; overflow:hidden; position:relative;">
        <div class="map-canvas-area" id="map_canvas" onwheel="BV4.map.zoom(event)" onmousedown="BV4.map.startPan(event)" onmousemove="BV4.map.pan(event)" onmouseup="BV4.map.endPan()" onmouseleave="BV4.map.endPan()">
            <svg id="map_svg" width="100%" height="100%" style="transform-origin:0 0;">
                <defs>
                    <marker id="map-arrow" markerWidth="8" markerHeight="8" refX="22" refY="4" orient="auto"><polygon points="0 0, 8 4, 0 8" fill="#5a6b5a"/></marker>
                    <marker id="map-arrow-fw" markerWidth="8" markerHeight="8" refX="22" refY="4" orient="auto"><polygon points="0 0, 8 4, 0 8" fill="#4CAF50"/></marker>
                    <marker id="map-arrow-bw" markerWidth="8" markerHeight="8" refX="22" refY="4" orient="auto"><polygon points="0 0, 8 4, 0 8" fill="#FF9800"/></marker>
                </defs>
                <rect width="10000" height="10000" x="-5000" y="-5000" fill="transparent" onclick="BV4.map.deselectNode()"></rect>
                <g id="map_edges"></g>
                <g id="map_nodes"></g>
            </svg>
            <div class="map-controls">
                <button onclick="BV4.map.doZoom(1.25)" title="Přiblížit">+</button>
                <button onclick="BV4.map.doZoom(0.8)" title="Oddálit">−</button>
            </div>
        </div>
        <div style="width:320px; background:var(--surface); border-left:1px solid var(--border); padding:1rem; overflow-y:auto;" id="map_panel">
            <div class="text-dim text-sm" style="text-align:center; margin-top:2rem;">Klikněte na uzel v mapě pro trasování.</div>
        </div>
    </div>
  </div>
</div>

<div class="bv4-sandbox" id="bv4_sandbox">
  <div class="bv4-sb-head">
    <div style="display:flex; align-items:center; gap:0.5rem;">
      <strong style="color:var(--primary);">🧪 Virtuální Sandbox</strong>
      <span id="sb_uzel_label" class="text-dim text-xs">— žádný uzel —</span>
    </div>
    <div style="display:flex; gap:0.4rem;">
      <button class="btn btn-xs" style="background:var(--info); color:#fff; border:none;" onclick="BV4.sandbox.showGuide()">📖 Návod</button>
      <button class="btn btn-outline btn-xs" onclick="BV4.sandbox.generateData()">🎲 Generovat data</button>
      <button class="btn btn-outline btn-xs" onclick="BV4.sandbox.testChain()">⛓ Test řetězce</button>
      <button class="btn btn-xs" onclick="BV4.sandbox.run()">▶ Spustit test</button>
      <button class="btn btn-outline btn-xs" onclick="BV4.sandbox.toggle()">✕</button>
    </div>
  </div>
  <div class="bv4-sb-body">
    <div class="bv4-sb-left">
      <div class="text-xs text-dim" style="margin-bottom:0.3rem">Vstupní data (JSON):</div>
      <textarea id="sb_input" class="input monospace" style="height:140px; font-size:0.75rem;">{}</textarea>
      <div class="text-xs text-dim" style="margin-top:0.5rem; margin-bottom:0.3rem">Řetězec uzlů:</div>
      <div id="sb_chain" style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; padding:0.5rem; background:var(--bg); border-radius:8px;"></div>
    </div>
    <div class="bv4-sb-right">
      <div class="text-xs text-dim" style="margin-bottom:0.3rem">Výsledek:</div>
      <div class="bv4-sb-result" id="sb_output">Zatím bez výsledku.</div>
      <div class="text-xs text-dim" style="margin-top:0.5rem; margin-bottom:0.3rem">Log:</div>
      <div class="bv4-sb-result" id="sb_log" style="max-height:80px;"></div>
    </div>
  </div>
</div>

<?php $html = ob_get_clean(); ?>

<?php $js = <<<'JSEOF'
window.BV4 = {
  projectId: null, projectName: null, _dragSrcId: null,

  async init() {
    PC.toast('Iniciuji jádro Stavebnice...', 'ok');
    try {
      const r = await PC.api('stav','scan_context');
      PC.toast(`Sken sítě: ${r.celkem} uzlů`, 'ok');
    } catch(e) { PC.toast('Scan selhal: ' + e.message, 'err'); }
    
    await this.components.load();
    await this.synapse.load();
    this.history.save();

    document.addEventListener('keydown', e => {
      if ((e.ctrlKey||e.metaKey) && e.key==='z') { e.preventDefault(); this.history.undo(); }
      if ((e.ctrlKey||e.metaKey) && e.key==='y') { e.preventDefault(); this.history.redo(); }
    });
  },

  map: {
    scale: 0.8, panX: 50, panY: 50, isDragging: false, startX: 0, startY: 0,
    nodeElements: {}, edgeElements: [], currentHighlight: null, uzly: [], vazby: [],

    async show() {
      PC.toast('Skenuji aktuální logiku a sestavuji mapu...', 'ok');
      try { await PC.api('stav', 'scan_context'); } catch(e){}
      
      this.uzly = await PC.api('stav', 'synapse_search', {query:''});
      this.vazby = await PC.api('stav', 'synapse_vazby', {akce:'list'});
      
      document.getElementById('bv4_map_overlay').style.display = 'flex';
      
      this.svg = document.getElementById('map_svg');
      this.gNodes = document.getElementById('map_nodes');
      this.gEdges = document.getElementById('map_edges');
      
      this.layoutGraph();
      this.renderGraph();
      this.resetView();
    },

    getColor(type) {
        const map = { 'db_tabulka': '#f44336', 'modul': '#FF9800', 'api': '#4CAF50', 'js': '#2196F3', 'css_var': '#9C27B0', 'html_komponenta': '#e91e63' };
        return map[type] || '#607D8B';
    },
    getLayer(type) {
        const map = { 'db_tabulka':0, 'modul':1, 'api':2, 'js':3, 'html_komponenta':4, 'css_var':5 };
        return map[type] !== undefined ? map[type] : 6;
    },

    layoutGraph() {
        const layers = {}; for(let i=0; i<=6; i++) layers[i] = [];
        this.uzly.forEach(u => layers[this.getLayer(u.typ)].push(u));
        
        Object.keys(layers).forEach(layerIdx => {
            const nodes = layers[layerIdx];
            nodes.forEach((n, i) => {
                n.x = 80 + (layerIdx * 260);
                n.y = 80 + (i * 60);
            });
        });
    },

    renderGraph() {
        this.gEdges.innerHTML = ''; this.gNodes.innerHTML = ''; this.edgeElements = [];
        this.vazby.forEach(v => {
            const z = this.uzly.find(u => u.id === v.z_uzlu);
            const d = this.uzly.find(u => u.id === v.do_uzlu);
            if(z && d) {
                const line = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                line.setAttribute('d', `M ${z.x} ${z.y} C ${z.x + 100} ${z.y}, ${d.x - 100} ${d.y}, ${d.x} ${d.y}`);
                line.setAttribute('stroke', '#3a4a3a'); line.setAttribute('stroke-width', '1.5'); line.setAttribute('fill', 'none'); line.setAttribute('marker-end', 'url(#map-arrow)');
                line.dataset.source = z.id; line.dataset.target = d.id;
                this.gEdges.appendChild(line);
                this.edgeElements.push({ el: line, source: z.id, target: d.id, vazba: v });
            }
        });

        this.uzly.forEach(n => {
            const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            g.setAttribute('transform', `translate(${n.x}, ${n.y})`);
            g.style.cursor = 'pointer';
            
            const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
            circle.setAttribute('r', '14'); circle.setAttribute('fill', '#141814'); circle.setAttribute('stroke', this.getColor(n.typ)); circle.setAttribute('stroke-width', '3');
            
            const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            text.setAttribute('text-anchor', 'middle'); text.setAttribute('fill', '#e5ebe5'); text.textContent = n.id;

            g.appendChild(circle); g.appendChild(text);
            g.onclick = (e) => { e.stopPropagation(); this.selectNode(n.id); };
            
            this.gNodes.appendChild(g); this.nodeElements[n.id] = g;
        });
    },

    selectNode(nodeId) {
        this.currentHighlight = nodeId;
        const nodeData = this.uzly.find(u => u.id === nodeId);
        if(!nodeData) return;

        Object.values(this.nodeElements).forEach(el => el.setAttribute('opacity', '0.15'));
        this.edgeElements.forEach(e => { e.el.setAttribute('stroke', '#243524'); e.el.setAttribute('stroke-width', '1'); e.el.setAttribute('marker-end', ''); });

        const fNodes = new Set([nodeId]), bNodes = new Set([nodeId]), fEdges = [], bEdges = [];
        const traverseFw = (id) => { this.edgeElements.filter(e => e.source === id).forEach(e => { fEdges.push(e); fNodes.add(e.target); traverseFw(e.target); }); };
        const traverseBw = (id) => { this.edgeElements.filter(e => e.target === id).forEach(e => { bEdges.push(e); bNodes.add(e.source); traverseBw(e.source); }); };

        traverseFw(nodeId); traverseBw(nodeId);

        fNodes.forEach(id => { if(this.nodeElements[id]) this.nodeElements[id].setAttribute('opacity', '1'); });
        bNodes.forEach(id => { if(this.nodeElements[id]) this.nodeElements[id].setAttribute('opacity', '1'); });

        fEdges.forEach(e => { e.el.setAttribute('stroke', '#4CAF50'); e.el.setAttribute('stroke-width', '2.5'); e.el.setAttribute('marker-end', 'url(#map-arrow-fw)'); });
        bEdges.forEach(e => { e.el.setAttribute('stroke', '#FF9800'); e.el.setAttribute('stroke-width', '2.5'); e.el.setAttribute('marker-end', 'url(#map-arrow-bw)'); });
        
        this.nodeElements[nodeId].setAttribute('opacity', '1');
        this.nodeElements[nodeId].querySelector('circle').setAttribute('stroke-width', '5');

        let html = `<h3 style="color:var(--primary);margin-top:0;">${_esc(nodeData.nazev)}</h3>`;
        html += `<div class="text-xs" style="margin-bottom:1rem;"><strong>ID:</strong> <span style="color:#fff">${_esc(nodeData.id)}</span><br><strong>Typ:</strong> <span class="badge" style="background:${this.getColor(nodeData.typ)};color:#fff;border:none;">${_esc(nodeData.typ)}</span></div>`;
        html += `<p class="text-sm">${_esc(nodeData.popis || '')}</p>`;

        const dFw = this.edgeElements.filter(e => e.source === nodeId);
        html += `<hr class="divider"><strong class="text-sm">Odesílá do:</strong><ul style="font-size:0.75rem; color:#4CAF50; padding-left:1rem;">`;
        if(dFw.length) dFw.forEach(e => html += `<li style="cursor:pointer;" onclick="BV4.map.selectNode('${e.target}')">${_esc(e.target)}</li>`); else html += `<li><span class="text-dim">Koncový uzel</span></li>`;
        
        const dBw = this.edgeElements.filter(e => e.target === nodeId);
        html += `</ul><strong class="text-sm" style="display:block;margin-top:1rem;">Přijímá z:</strong><ul style="font-size:0.75rem; color:#FF9800; padding-left:1rem;">`;
        if(dBw.length) dBw.forEach(e => html += `<li style="cursor:pointer;" onclick="BV4.map.selectNode('${e.source}')">${_esc(e.source)}</li>`); else html += `<li><span class="text-dim">Počáteční uzel</span></li>`;
        
        html += `</ul><hr class="divider"><button class="btn btn-sm" style="width:100%;" onclick="BV4.map.openInInspector('${nodeData.id}')">🔍 Detail v Inspektoru</button>`;
        document.getElementById('map_panel').innerHTML = html;
    },

    openInInspector(id) {
        document.getElementById('bv4_map_overlay').style.display = 'none';
        BV4.inspector.showById(id);
    },

        deselectNode() {
        if(this.dragDist > 5) return; // Pokud jsme tahali mapu, nezruší se označení
        if(!this.currentHighlight) return;
        this.currentHighlight = null;
        Object.values(this.nodeElements).forEach(el => { el.setAttribute('opacity', '1'); el.querySelector('circle').setAttribute('stroke-width', '3'); });
        this.edgeElements.forEach(e => { e.el.setAttribute('stroke', '#3a4a3a'); e.el.setAttribute('stroke-width', '1.5'); e.el.setAttribute('marker-end', 'url(#map-arrow)'); });
        document.getElementById('map_panel').innerHTML = '<div class="text-dim text-sm" style="text-align:center; margin-top:2rem;">Klikněte na uzel pro trasování.<br><br>Kliknutím na pozadí výběr zrušíte.</div>';
    },

    updateTransform() {
        if (!this.gNodes) return;
        this.gNodes.setAttribute('transform', `translate(${this.panX},${this.panY}) scale(${this.scale})`);
        this.gEdges.setAttribute('transform', `translate(${this.panX},${this.panY}) scale(${this.scale})`);
        
        // Zajištění minimální a maximální velikosti fontu (vždy čitelné)
        let cSize = Math.max(8, Math.min(24, 12 / this.scale));
        document.querySelectorAll('#map_nodes text').forEach(t => { 
            t.setAttribute('font-size', `${cSize}px`); 
            t.setAttribute('y', 16 + (cSize * 0.8)); 
        });
    },

    doZoom(factor) { this.scale *= factor; this.updateTransform(); },
    zoom(e) { e.preventDefault(); this.doZoom(e.deltaY < 0 ? 1.1 : 0.9); },
        startPan(e) { this.isDragging = true; this.dragDist = 0; this.startX = e.clientX - this.panX; this.startY = e.clientY - this.panY; },
        pan(e) { if(!this.isDragging) return; this.dragDist += Math.abs(e.movementX) + Math.abs(e.movementY); this.panX = e.clientX - this.startX; this.panY = e.clientY - this.startY; this.updateTransform(); },
    endPan() { this.isDragging = false; },
    resetView() { this.scale = 0.8; this.panX = 50; this.panY = 50; this.updateTransform(); this.deselectNode(); }
  },

  sidebar: {
    show(tab) {
      ['lib','syn'].forEach(t => {
        document.getElementById('panel_'+t)?.classList.toggle('active', t===tab);
        document.getElementById('tab_'+t)?.classList.toggle('active', t===tab);
      });
    }
  },

  history: {
    _states: [], _idx: -1,
    save() {
      const h = document.getElementById('b_canvas').innerHTML;
      if (this._idx >= 0 && this._states[this._idx] === h) return;
      this._states = this._states.slice(0, this._idx+1);
      this._states.push(h);
      if (this._states.length > 60) this._states.shift(); else this._idx++;
    },
    undo() { if (this._idx > 0) { this._idx--; this._apply(); } },
    redo() { if (this._idx < this._states.length-1) { this._idx++; this._apply(); } },
    _apply() { document.getElementById('b_canvas').innerHTML = this._states[this._idx]; BV4._syncEmpty(); BV4.dnd.rebindAll(); },
    async showModal() {
      const list = await PC.api('system','history_list');
      let rows = '';
      (list||[]).forEach(f => {
        const d = new Date(f.mtime*1000).toLocaleString('cs');
        rows += `<div style="display:flex;justify-content:space-between;padding:4px;border-bottom:1px solid var(--border);"><span>${_esc(f.file)}</span> <button class="btn btn-outline btn-xs" onclick="BV4.history._restore('${_esc(f.file)}')">↩ Obnovit</button></div>`;
      });
      BV4._modal(`<h3>📜 Historie záloh</h3><div style="max-height:50vh; overflow-y:auto;">${rows || '<div class="text-dim">Žádné zálohy.</div>'}</div><div class="bv4-modal-foot"><button class="btn btn-xs" onclick="BV4._closeModal()">Zavřít</button></div>`);
    },
    async _restore(file) {
      if (!confirm(`Obnovit zálohu: ${file}?`)) return;
      try { await PC.api('system','history_restore',{file}); PC.toast('Obnoveno','ok'); BV4._closeModal(); await BV4.synapse.load(); } catch(e) { PC.toast(e.message,'err'); }
    }
  },

    async deployPlatform() {
      if(!confirm('POZOR: Tato akce vezme zdrojové kódy (fragmenty) uložené v Inspektoru (databázi) a přepíše jimi fyzické .php soubory na serveru. Pokračovat?')) return;
      PC.toast('Sestavuji platformu...', 'warn');
      try {
          const r = await PC.api('stav', 'deploy_platform');
          BV4._modal(`<h3>🚀 Výsledek sestavení</h3><p>${_esc(r.message)}</p><div class="bv4-sb-result" style="max-height:300px;">${r.logs.join('<br>')}</div>`);
          PC.toast('Sestavení dokončeno', 'ok');
      } catch(e) { PC.toast('Chyba sestavení: ' + e.message, 'err'); }
  },

  components: {
    _list: [],
    async load() { try { this._list = await PC.api('stav','components',{akce:'list'}); } catch(e) { this._list = []; } this.render(this._list); },
    filter(q) { const f = q.toLowerCase(); this.render(f ? this._list.filter(c => c.nazev.toLowerCase().includes(f) || (c.tags||[]).some(t=>t.toLowerCase().includes(f))) : this._list); },
    render(items) {
      const lib = document.getElementById('b_library'); if (!lib) return;
      if (!items.length) { lib.innerHTML = '<div class="text-dim text-sm" style="text-align:center">Žádné komponenty</div>'; return; }
      lib.innerHTML = '';
      items.forEach(c => {
        const div = document.createElement('div'); div.className = 'bv4-item';
        div.innerHTML = `<span>${_esc(c.nazev)}</span> <div style="display:flex;gap:2px"><button class="bv4-nc" title="Přidat" onclick="BV4.canvas.add('${_esc(c.id)}')">➕</button> <button class="bv4-nc" title="Upravit" onclick="BV4.components.edit('${_esc(c.id)}')">✏️</button> <button class="bv4-nc" title="Duplikovat" onclick="BV4.components.duplicate('${_esc(c.id)}')">📑</button></div>`;
        lib.appendChild(div);
      });
    },
        duplicate(id) {
        const c = this._list.find(x=>x.id===id); if(!c) return;
        this.edit(null);
        document.getElementById('ce_n').value = c.nazev + ' (Kopie)';
        document.getElementById('ce_h').value = c.html;
    },
    edit(id=null) {
      const c = id ? (this._list.find(x=>x.id===id)||{}) : {};
      BV4._modal(`
        <h3>${id?'Upravit':'Nová'} komponenta</h3>
        <div class="form-group"><label>Název</label><input type="text" id="ce_n" class="input" value="${_esc(c.nazev||'')}"></div>
        <div class="form-group"><label>HTML kód</label><textarea id="ce_h" class="input monospace" style="height:120px;">${_esc(c.html||'')}</textarea></div>
        <div class="bv4-modal-foot">
          ${id?`<button class="btn btn-outline btn-xs" style="color:var(--danger);margin-right:auto" onclick="BV4.components._del('${_esc(c.id||'')}')">🗑 Smazat</button>`:''}
          <button class="btn btn-outline btn-xs" onclick="BV4._closeModal()">Zrušit</button>
          <button class="btn btn-xs" onclick="BV4.components._save('${_esc(c.id||'')}')">Uložit</button>
        </div>`);
    },
    async _save(id) {
      const n = document.getElementById('ce_n')?.value?.trim(), h = document.getElementById('ce_h')?.value?.trim();
      if (!n||!h) return PC.toast('Název a HTML jsou povinné','err');
      await PC.api('stav','components',{akce:'save',id,nazev:n,html:h});
      BV4._closeModal(); await this.load(); PC.toast('Komponenta uložena');
    },
    async _del(id) { if (!confirm('Smazat?')) return; await PC.api('stav','components',{akce:'delete',id}); BV4._closeModal(); await this.load(); },
    async scan() { const r = await PC.api('stav','components',{akce:'scan_from_platform'}); PC.toast(r.message); await this.load(); }
  },

  canvas: {
    add(compId) {
      const c = BV4.components._list.find(x=>x.id===compId); if (!c) return;
      const uid = 'bn_' + Math.random().toString(36).substr(2,7);
      const el = document.createElement('div'); el.className = 'bv4-node'; el.id = uid; el.draggable = true; el.dataset.compId = compId;
      el.innerHTML = `<div class="bv4-node-ctrl"><button class="bv4-nc" onclick="BV4.canvas.move('${uid}',-1)">⬆</button><button class="bv4-nc" onclick="BV4.canvas.move('${uid}',1)">⬇</button><button class="bv4-nc" onclick="BV4.synapse.openLink('${uid}')">🔗</button><button class="bv4-nc" onclick="BV4.sandbox.selectNode('${uid}')">🧪</button><button class="bv4-nc" onclick="BV4.inspector.show('${uid}')">🔬</button><button class="bv4-nc red" onclick="BV4.canvas.remove('${uid}')">✕</button></div><div class="bv4-content" data-synapse="">${c.html}</div>`;
      document.getElementById('b_canvas').appendChild(el); BV4._syncEmpty(); BV4.history.save(); BV4.dnd.bind(el);
    },
    move(uid, dir) { const el = document.getElementById(uid); if (!el) return; const p = el.parentNode; if (dir===-1 && el.previousElementSibling) p.insertBefore(el, el.previousElementSibling); if (dir===1 && el.nextElementSibling) p.insertBefore(el, el.nextElementSibling.nextElementSibling); BV4.history.save(); },
    remove(uid) { document.getElementById(uid)?.remove(); BV4._syncEmpty(); BV4.history.save(); },
    clear() { if (!confirm('Vyčistit plátno?')) return; document.getElementById('b_canvas').innerHTML = ''; BV4._syncEmpty(); BV4.history.save(); }
  },

  dnd: {
    bind(el) { el.addEventListener('dragstart', e => { BV4._dragSrcId=el.id; el.classList.add('dragging'); e.dataTransfer.effectAllowed='move'; }); el.addEventListener('dragend', () => { el.classList.remove('dragging'); document.querySelectorAll('.bv4-node').forEach(n=>n.classList.remove('drag-over')); }); el.addEventListener('dragenter', e => { e.preventDefault(); el.classList.add('drag-over'); }); el.addEventListener('dragleave', () => el.classList.remove('drag-over')); },
    rebindAll() { document.querySelectorAll('.bv4-node').forEach(n=>this.bind(n)); },
    onDragOver(e) { e.preventDefault(); e.dataTransfer.dropEffect='move'; },
    onDrop(e) { e.preventDefault(); const src = document.getElementById(BV4._dragSrcId); if (!src) return; const target = e.target.closest('.bv4-node'); const canvas = document.getElementById('b_canvas'); if (target && target!==src) canvas.insertBefore(src, target); else if (!target) canvas.appendChild(src); src.classList.remove('dragging'); document.querySelectorAll('.bv4-node').forEach(n=>n.classList.remove('drag-over')); BV4.history.save(); }
  },

  synapse: {
    _all: [], _filters: {},
    async load() { try { this._all = await PC.api('stav','synapse_search',{query:''}); } catch(e) { this._all = []; } this.renderList(this._all); },
    setFilter(key, val) { this._filters[key] = val; if (key==='skupina') { ['all','fyz','abs'].forEach(k=>document.getElementById('sf_'+k)?.classList.remove('active')); const map={'':'all','fyzicka':'fyz','abstraktni':'abs'}; if (map[val]) document.getElementById('sf_'+map[val])?.classList.add('active'); } this.search(document.getElementById('syn_q')?.value||''); },
    async search(q) { try { const r = await PC.api('stav','synapse_search',{query:q, ...this._filters}); this.renderList(r); } catch(e) {} },
    renderList(items) {
      const box = document.getElementById('syn_list'); if (!box) return;
      if (!items.length) { box.innerHTML = '<div class="text-dim text-sm" style="text-align:center">Žádné uzly</div>'; return; }
      box.innerHTML = '';
      items.forEach(u => {
        const d = document.createElement('div'); d.className='bv4-item';
        d.innerHTML = `<span><span class="typ-badge typ-badge-${u.typ}">${u.typ}</span> ${_esc(u.nazev)}</span> <div style="display:flex;gap:2px"><button class="bv4-nc" title="Sandbox" onclick="BV4.sandbox.addToChain('${_esc(u.id)}')">⛓</button><button class="bv4-nc" title="Inspektor" onclick="BV4.inspector.showById('${_esc(u.id)}')">🔬</button></div>`;
        box.appendChild(d);
      });
    },
    _curCanvas: null,
    async openLink(canvasId) {
      this._curCanvas = canvasId;
      const cur = document.querySelector(`#${canvasId} .bv4-content`)?.dataset.synapse || '';
      BV4._modal(`<h3>🔗 Propojit uzel</h3><input type="text" id="lm_q" class="input" placeholder="Hledat uzel…" oninput="BV4.synapse._linkSearch(this.value)" style="margin-bottom:.5rem"><div id="lm_res" style="max-height:280px;overflow-y:auto;border:1px solid var(--border);border-radius:6px;padding:.3rem;"></div><div class="bv4-modal-foot"><button class="btn btn-outline btn-xs" style="color:var(--danger);margin-right:auto" onclick="BV4.synapse.assign('')">Odstranit vazbu</button><button class="btn btn-xs" onclick="BV4._closeModal()">Zavřít</button></div>`);
      this._linkSearch('');
    },
    async _linkSearch(q) {
      const res = await PC.api('stav','synapse_search',{query:q});
      const box = document.getElementById('lm_res'); if (!box) return; box.innerHTML = '';
      res.forEach(u => {
        const d = document.createElement('div'); d.className='bv4-item';
        d.innerHTML = `<div><span class="typ-badge typ-badge-${u.typ}">${u.typ}</span> <strong>${_esc(u.nazev)}</strong><br><small class="text-dim">${_esc(u.id)}</small></div><button class="btn btn-xs" onclick="BV4.synapse.assign('${_esc(u.id)}')">Vybrat</button>`;
        box.appendChild(d);
      });
    },
    assign(synapseId) {
      const cid = this._curCanvas; if (!cid) return;
      const cont = document.querySelector(`#${cid} .bv4-content`);
      if (cont) { cont.dataset.synapse = synapseId; cont.querySelectorAll('.bv4-synapse-badge').forEach(b=>b.remove()); if (synapseId) { const b = document.createElement('span'); b.className='bv4-synapse-badge'; b.textContent = '🔗 ' + synapseId; cont.appendChild(b); } }
      BV4._closeModal(); BV4.history.save(); PC.toast('Vazba: ' + (synapseId||'odstraněna'));
    }
  },

  inspector: {
    _histIds: [], _histIdx: -1, _curId: null,
    toggle() { document.getElementById('bv4_inspector')?.classList.toggle('open'); },
    show(canvasId) { const id = document.querySelector(`#${canvasId} .bv4-content`)?.dataset.synapse; if (!id) return PC.toast('Nejprve přiřaďte Synapse vazbu (🔗).','warn'); this.showById(id); },
    showById(id) { document.getElementById('bv4_inspector')?.classList.add('open'); this._curId = id; this._histIds = this._histIds.slice(0, this._histIdx+1); this._histIds.push(id); this._histIdx++; this._load(id); },
    goBack() { if (this._histIdx > 0) { this._histIdx--; this._load(this._histIds[this._histIdx]); } },
    goForward() { if (this._histIdx < this._histIds.length-1) { this._histIdx++; this._load(this._histIds[this._histIdx]); } },
    async _load(id) {
      const c = document.getElementById('bv4_insp_content'); c.innerHTML = '<div class="text-dim">Načítám…</div>';
      try {
        const r = await PC.api('stav','synapse_detail',{id}); const u = r.uzel;
        c.innerHTML = `<div style="display:flex;justify-content:space-between;margin-bottom:.5rem"><div><button class="btn btn-outline btn-xs" onclick="BV4.inspector.goBack()">←</button> <button class="btn btn-outline btn-xs" onclick="BV4.inspector.goForward()">→</button></div> <button class="btn btn-xs" onclick="BV4.inspector._save()">💾 Uložit kód</button></div>
          <div style="margin-bottom:.5rem"><strong style="color:var(--primary);font-size:.92rem">${_esc(u.nazev)}</strong><br><span class="typ-badge typ-badge-${u.typ}">${u.typ}</span><span class="text-xs text-dim"> ${_esc(id)}</span></div>
          <div class="form-group"><label class="text-xs text-dim">Anotace:</label><textarea id="insp_popis" class="input" style="height:50px;font-size:.75rem;">${_esc(u.popis||'')}</textarea></div>
          <div class="form-group"><label class="text-xs text-dim">Zdrojový kód:</label><textarea id="insp_kod" class="input monospace" style="height:150px;font-size:.72rem;background:#0d1117;color:#c9d1d9;">${_esc(u.kod||'')}</textarea></div>
          <button class="btn btn-outline btn-xs" style="width:100%" onclick="BV4.sandbox.selectNodeById('${_esc(id)}')">🎯 Testovat v Sandboxu</button>`;
      } catch(e) { c.innerHTML = `Chyba: ${_esc(e.message)}`; }
    },
    async _save() {
      const id = this._curId; if (!id) return;
      await PC.api('stav','save_ide_node',{id, popis: document.getElementById('insp_popis')?.value||'', kod: document.getElementById('insp_kod')?.value||''});
      PC.toast('Uloženo','ok');
    }
  },

  sandbox: {
    _targetId: null, _chain: [],
    toggle() { document.getElementById('bv4_sandbox')?.classList.toggle('open'); },
    selectNode(canvasId) { const id = document.querySelector(`#${canvasId} .bv4-content`)?.dataset.synapse; if (!id) return PC.toast('Přiřaďte Synapse vazbu (🔗).','warn'); this.selectNodeById(id); },
    selectNodeById(id) { this._targetId = id; document.getElementById('sb_uzel_label').textContent = '→ ' + id; document.getElementById('bv4_sandbox')?.classList.add('open'); },
    addToChain(id) { if (!this._chain.includes(id)) this._chain.push(id); this._renderChain(); document.getElementById('bv4_sandbox')?.classList.add('open'); },
    removeFromChain(id) { this._chain = this._chain.filter(x=>x!==id); this._renderChain(); },
    _renderChain() {
      const box = document.getElementById('sb_chain'); if (!box) return;
      box.innerHTML = this._chain.length ? '' : '<span class="text-dim text-xs">Přidejte uzly (⛓)</span>';
      this._chain.forEach((id, i) => {
        if (i>0) box.innerHTML += `<span class="text-dim">→</span>`;
        box.innerHTML += `<span style="background:var(--surface);border:1px solid var(--primary);border-radius:8px;padding:2px 6px;font-size:.75rem;cursor:pointer;" onclick="BV4.sandbox.removeFromChain('${id}')">${id}</span>`;
      });
    },
    showGuide() {
      BV4._modal(`
        <div style="padding-bottom:1rem; border-bottom:1px solid var(--border); margin-bottom:1rem;">
            <h2 style="color:var(--info); margin:0;">📖 Návod: Jak vytvořit a nasadit nový modul</h2>
        </div>
        <div style="font-size:0.85rem; max-height:60vh; overflow-y:auto;">
            <strong style="color:var(--primary)">1. Vytvoření UI</strong><br>V záložce 📦 Knihovna navrhněte prvky a umístěte je na plátno.<br><br>
            <strong style="color:var(--primary)">2. Přiřazení a sepsání logiky</strong><br>Přes tlačítko 🔗 přiřaďte bloku Synapse Uzel. V 🔬 Inspektoru následně napište do pole "Zdrojový kód" PHP nebo JS kód dané logiky.<br><br>
            <strong style="color:var(--primary)">3. Testování logiky nanečisto (Zde)</strong><br>Přidejte uzel přes 🧪 nebo ⛓ do Sandboxu. Klikněte na 🎲 Generovat data pro doporučený vstup a stiskněte ▶ Spustit test.<br><br>
            <strong style="color:var(--primary)">4. Trvalé uložení do systému</strong><br>Uložte plátno (💾 Uložit) jako projekt. Pro zhmotnění kódu ze Sandboxu jděte do modulu Laboratoř a zvolte Nasadit do platformy.<br><br>
            <strong style="color:var(--primary)">5. Vizuální validace</strong><br>Po uložení si celou architekturu prohlédněte přes tlačítko 🗺️ Mapa.
        </div>
      `);
    },
    async generateData() {
      if (!this._targetId) return PC.toast('Vyberte cílový uzel','warn');
      try { const r = await PC.api('stav','sandbox',{akce:'generate_data',uzel_id:this._targetId}); document.getElementById('sb_input').value = JSON.stringify(r.doporucena, null, 2); PC.toast('Data vygenerována'); } catch(e) { PC.toast(e.message,'err'); }
    },
    async run() {
      if (!this._targetId) return PC.toast('Vyberte uzel','warn');
      let vstup = {}; try { vstup = JSON.parse(document.getElementById('sb_input')?.value||'{}'); } catch(e) { return PC.toast('Neplatný JSON','err'); }
      document.getElementById('sb_output').textContent = 'Testuji…';
      try { const r = await PC.api('stav','sandbox',{akce:'test_node',uzel_id:this._targetId,vstup}); document.getElementById('sb_output').textContent = JSON.stringify(r.vystup, null, 2); document.getElementById('sb_log').textContent = (r.log||[]).join('\n'); PC.toast(`Test ${r.stav==='ok'?'OK':'CHYBA'}`, r.stav==='ok'?'ok':'err'); } catch(e) { document.getElementById('sb_output').textContent='Chyba: '+e.message; }
    },
    async testChain() {
      if (!this._chain.length) return PC.toast('Přidejte uzly (⛓)','warn');
      let vstup = {}; try { vstup = JSON.parse(document.getElementById('sb_input')?.value||'{}'); } catch(e) { return PC.toast('Neplatný JSON','err'); }
      document.getElementById('sb_output').textContent = 'Testuji řetězec…';
      try { const r = await PC.api('stav','sandbox',{akce:'test_chain',chain:this._chain,vstup}); document.getElementById('sb_output').textContent = JSON.stringify(r.final, null, 2); document.getElementById('sb_log').textContent = (r.kroky||[]).map((k,i)=>`Krok ${i+1} [${k.uzel_id}]: ${k.stav}\n${(k.log||[]).join(' | ')}`).join('\n\n'); PC.toast(`Řetězec: ${r.stav==='ok'?'OK':'CHYBA'}`, r.stav==='ok'?'ok':'err'); } catch(e) { document.getElementById('sb_output').textContent='Chyba: '+e.message; }
    }
  },

  projects: {
    save() { BV4._modal(`<h3>💾 Uložit projekt</h3><input type="text" id="ps_n" class="input" value="${_esc(BV4.projectName||'')}" placeholder="Název projektu…"><div class="bv4-modal-foot"><button class="btn btn-xs" onclick="BV4.projects._doSave()">Uložit</button></div>`); setTimeout(() => document.getElementById('ps_n')?.focus(), 50); },
    async _doSave() {
      const name = document.getElementById('ps_n')?.value?.trim(); if (!name) return PC.toast('Název je povinný','err');
      try { const r = await PC.api('stav','projects',{akce:'save',id:BV4.projectId,nazev:name,canvas:document.getElementById('b_canvas').innerHTML}); if (!BV4.projectId) BV4.projectId = r; BV4.projectName = name; document.getElementById('bv4_proj_name').textContent = name; BV4._closeModal(); PC.toast('Uloženo'); } catch(e) { PC.toast(e.message,'err'); }
    },
    async showModal() {
      const list = await PC.api('stav','projects',{akce:'list'}); let items = '';
      (list||[]).forEach(p => { items += `<div class="bv4-item"><strong>${_esc(p.nazev)}</strong><div><button class="btn btn-outline btn-xs" onclick="BV4.projects._load('${_esc(p.id)}','${_esc(p.nazev)}')">📂 Načíst</button> <button class="btn btn-xs" style="background:var(--danger)" onclick="BV4.projects._del('${_esc(p.id)}')">🗑</button></div></div>`; });
      BV4._modal(`<h3>📂 Projekty</h3><div style="max-height:50vh;overflow-y:auto">${items||'<div class="text-dim text-sm">Žádné projekty.</div>'}</div>`);
    },
    async _load(id, name) { const p = await PC.api('stav','projects',{akce:'load',id}); document.getElementById('b_canvas').innerHTML = p.canvas||''; BV4.projectId = id; BV4.projectName = name; document.getElementById('bv4_proj_name').textContent = name; BV4._syncEmpty(); BV4.dnd.rebindAll(); BV4.history.save(); BV4._closeModal(); PC.toast('Načteno'); },
    async _del(id) { if (!confirm('Smazat projekt?')) return; await PC.api('stav','projects',{akce:'delete',id}); await this.showModal(); }
  },

  _syncEmpty() { const c = document.getElementById('b_canvas'), e = document.getElementById('bv4_empty'); if(c&&e) e.style.display = c.children.length <= 1 ? '' : 'none'; },
  _modal(html, w='600px') { this._closeModal(); const bg = document.createElement('div'); bg.id='bv4-modal-bg'; bg.className='bv4-modal-bg'; bg.onclick = e => { if (e.target===bg) this._closeModal(); }; bg.innerHTML = `<div class="bv4-modal" style="width:${w}">${html}</div>`; document.body.appendChild(bg); },
  _closeModal() { document.getElementById('bv4-modal-bg')?.remove(); }
};

// location.hash = 'Stavebnice' ;
  //  document.title = 'PC Stavebnice';BV4.init();

JSEOF;

return ['html' => $html, 'js' => $js . "\n\n"];