<?php
$db      = DB::getInstance();
$modules = Registry::getModules();
$uzly    = $db->run('synapse_uzly', 'select');
$vazby   = $db->run('synapse_vazby', 'select');
$sandbox = $db->run('synapse_sandbox', 'select', ['orderBy' => 'created_at', 'limit' => 5]);
$histFiles = glob(DB_PATH . '/his/*.db') ?: [];

ob_start();
?>
<div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:1rem; margin-bottom:1.5rem;">
  <div class="card" style="text-align:center">
    <div style="font-size:2rem; color:var(--primary)"><?= count($modules) ?></div>
    <div class="text-dim text-sm">Modulů</div>
  </div>
  <div class="card" style="text-align:center">
    <div style="font-size:2rem; color:var(--primary)"><?= count($uzly) ?></div>
    <div class="text-dim text-sm">Synapse uzlů</div>
  </div>
  <div class="card" style="text-align:center">
    <div style="font-size:2rem; color:var(--primary)"><?= count($vazby) ?></div>
    <div class="text-dim text-sm">Vazeb</div>
  </div>
  <div class="card" style="text-align:center">
    <div style="font-size:2rem; color:var(--primary)"><?= count($histFiles) ?></div>
    <div class="text-dim text-sm">Záloh v historii</div>
  </div>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
  <div class="card">
    <div class="card-header"><strong>📦 Moduly</strong></div>
    <?php foreach ($modules as $slug => $m): ?>
      <div class="flex-between" style="padding:.3rem 0; border-bottom:1px solid var(--border); font-size:.85rem">
        <span><?= $m['icon'] ?> <?= htmlspecialchars($m['name']) ?></span>
        <button class="btn btn-outline btn-xs" onclick="PC.router.load('<?= $slug ?>')">Otevřít</button>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <div class="card-header"><strong>🧪 Poslední sandbox testy</strong></div>
    <?php if (empty($sandbox)): ?>
      <div class="text-dim text-sm">Žádné testy.</div>
    <?php else: foreach ($sandbox as $s): ?>
      <div style="padding:.3rem 0; border-bottom:1px solid var(--border); font-size:.78rem">
        <span class="badge <?= $s['stav']==='ok' ? '' : 'badge-danger' ?>"><?= htmlspecialchars($s['stav']) ?></span>
        <strong> <?= htmlspecialchars($s['uzel_id']) ?></strong>
        <span class="text-dim"> <?= $s['cas_ms'] ?>ms — <?= $s['created_at'] ?></span>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php $html = ob_get_clean(); ?>

 <?php /* return ['html' => ob_get_clean()]; */ ?>

<?php $js = <<<'JSEOF'

// location.hash = 'Hlavní' ;
   // document.title = 'PC Hlavní';

JSEOF;

return ['html' => $html, 'js' => $js . "\n \n"];