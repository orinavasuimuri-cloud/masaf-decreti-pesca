<?php
declare(strict_types=1);

$dataDir = __DIR__ . '/data';
$catalog = json_decode((string) file_get_contents($dataDir . '/catalog.json'), true) ?? ['sections' => []];
$known   = json_decode((string) file_get_contents($dataDir . '/known.json'), true) ?? ['items' => []];

$sections = $catalog['sections'] ?? [];

$pending = array_filter($known['items'] ?? [], fn($i) => ($i['status'] ?? '') === 'pending_review');
usort($pending, fn($a, $b) => strcmp($b['first_seen'] ?? '', $a['first_seen'] ?? ''));

$lastRun = $known['_meta']['last_run'] ?? null;
$lastRunLabel = $lastRun ? date('d/m/Y H:i', strtotime($lastRun)) : 'mai eseguito';

// Fallback per voci senza campo 'year' esplicito (es. rilevate dallo scraper):
// \b evita di leggere per errore 4 cifre interne a un numero di decreto
// (es. "194803" non deve produrre l'anno "1948").
function extract_year(string $ref): string {
    if (preg_match('/\b(19|20)\d{2}\b/', $ref, $m)) {
        return $m[0];
    }
    return 'info';
}

function item_year(array $item): string {
    return $item['year'] ?? extract_year($item['ref'] ?? '');
}

$totalItems = 0;
$totalPdf = 0;
$years = [];
foreach ($sections as $section) {
    foreach ($section['items'] ?? [] as $item) {
        $totalItems++;
        if (!empty($item['pdf'])) {
            $totalPdf++;
        }
        $years[item_year($item)] = true;
    }
}
$years = array_keys($years);
sort($years);
// "info" in coda, il resto in ordine decrescente (più recenti prima)
usort($years, function ($a, $b) {
    if ($a === 'info') return 1;
    if ($b === 'info') return -1;
    return (int) $b <=> (int) $a;
});

function h(int|string|null $s): string {
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>Registro — Decreti pesca speciale e quote di cattura</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root {
    --ink: 18 40 43; --paper: 231 241 236; --paper-raised: 244 248 246;
    --line: 195 211 206; --muted: 74 96 92;
    --brass: 156 122 46; --brass-deep: 122 95 34; --shadow: 18 40 43;
    --warn: 176 108 46;
  }
  @media (prefers-color-scheme: dark) {
    :root { --ink: 220 234 229; --paper: 12 22 24; --paper-raised: 20 33 35;
      --line: 40 58 58; --muted: 150 172 166; --brass: 199 154 66; --brass-deep: 224 178 92; --shadow: 0 0 0; }
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: rgb(var(--paper)); color: rgb(var(--ink));
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    line-height: 1.5; -webkit-font-smoothing: antialiased; }
  a { color: rgb(var(--brass-deep)); }
  .wrap { max-width: 1080px; margin: 0 auto; padding: 3rem 1.5rem 5rem; }
  .masthead { border-bottom: 1px solid rgb(var(--line)); padding-bottom: 2rem; margin-bottom: 1.75rem; }
  .eyebrow { font-family: ui-monospace, monospace; font-size: 0.72rem; letter-spacing: 0.14em;
    text-transform: uppercase; color: rgb(var(--brass-deep)); display: flex; align-items: center; gap: 0.6em; margin-bottom: 1rem; }
  .eyebrow::before { content: ""; width: 1.6em; height: 1px; background: rgb(var(--brass-deep)); display: inline-block; }
  h1 { font-family: Georgia, "Times New Roman", serif; font-weight: 600; font-size: clamp(1.9rem, 4vw, 2.9rem);
    line-height: 1.08; margin: 0 0 0.9rem; }
  .lede { max-width: 66ch; color: rgb(var(--muted)); font-size: 1.02rem; margin: 0 0 1.4rem; }
  .meta-row { display: flex; flex-wrap: wrap; gap: 1.6rem; font-family: ui-monospace, monospace;
    font-variant-numeric: tabular-nums; font-size: 0.78rem; color: rgb(var(--muted)); }
  .meta-row strong { color: rgb(var(--ink)); font-weight: 600; }
  .index { position: sticky; top: 0; z-index: 10; background: rgb(var(--paper) / 0.92); backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px); border-bottom: 1px solid rgb(var(--line)); margin: 0 -1.5rem 1.2rem;
    padding: 0.8rem 1.5rem; display: flex; gap: 0.5rem; overflow-x: auto; }
  .chip { flex: none; display: inline-flex; align-items: center; gap: 0.45em; padding: 0.4rem 0.75rem;
    border-radius: 999px; border: 1px solid rgb(var(--line)); font-size: 0.78rem; text-decoration: none;
    color: rgb(var(--ink)); white-space: nowrap; }
  .chip .dot { width: 0.55em; height: 0.55em; border-radius: 50%; background: rgb(var(--chip-c)); display: inline-block; }
  .year-filter { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; margin: 0 0 2.2rem; }
  .year-filter .label { font-family: ui-monospace, monospace; font-size: 0.72rem; letter-spacing: 0.08em;
    text-transform: uppercase; color: rgb(var(--muted)); margin-right: 0.3rem; }
  .yr-chip { appearance: none; font: inherit; cursor: pointer; padding: 0.35rem 0.8rem; border-radius: 999px;
    border: 1px solid rgb(var(--line)); background: transparent; color: rgb(var(--ink)); font-size: 0.78rem;
    font-variant-numeric: tabular-nums; }
  .yr-chip[aria-pressed="true"] { background: rgb(var(--brass) / 0.16); border-color: rgb(var(--brass));
    color: rgb(var(--brass-deep)); font-weight: 600; }
  section.category { margin-bottom: 3rem; scroll-margin-top: 4.5rem; }
  .cat-head { display: flex; align-items: baseline; gap: 0.75rem; border-left: 3px solid rgb(var(--cat-c));
    padding-left: 0.9rem; margin-bottom: 0.4rem; }
  .cat-head h2 { font-family: Georgia, serif; font-weight: 600; font-size: 1.4rem; margin: 0; }
  .cat-head .count { font-family: ui-monospace, monospace; font-size: 0.75rem; color: rgb(var(--muted)); }
  .cat-desc { color: rgb(var(--muted)); font-size: 0.92rem; max-width: 68ch; margin: 0.3rem 0 1.2rem 0.9rem; }
  .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 0.9rem; }
  .card { display: flex; flex-direction: column; gap: 0.55rem; background: rgb(var(--paper-raised));
    border: 1px solid rgb(var(--line)); border-left: 3px solid rgb(var(--cat-c)); border-radius: 6px;
    padding: 1rem 1.1rem 1.05rem; text-decoration: none; color: rgb(var(--ink));
    box-shadow: 0 1px 2px rgb(var(--shadow) / 0.04); }
  .card .ref { font-family: ui-monospace, monospace; font-variant-numeric: tabular-nums; font-size: 0.72rem;
    letter-spacing: 0.02em; color: rgb(var(--cat-c)); text-transform: uppercase; }
  .card h3 { font-family: Georgia, serif; font-weight: 600; font-size: 1.03rem; line-height: 1.3; margin: 0; }
  .card p { font-size: 0.88rem; color: rgb(var(--muted)); margin: 0; line-height: 1.55; }
  .card .source { margin-top: auto; padding-top: 0.6rem; font-size: 0.76rem; color: rgb(var(--brass-deep));
    display: flex; align-items: center; gap: 0.35em; }
  .card .source::after { content: "↗"; font-size: 0.9em; }
  .doc-list { display: flex; flex-direction: column; border: 1px solid rgb(var(--line)); border-radius: 8px; overflow: hidden; }
  .doc-list > * + * { border-top: 1px solid rgb(var(--line)); }
  .doc-group-label { font-family: ui-monospace, monospace; font-size: 0.7rem; letter-spacing: 0.09em;
    text-transform: uppercase; color: rgb(var(--muted)); padding: 0.7rem 1.1rem 0.35rem; background: rgb(var(--paper)); }
  .doc-row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 1rem; padding: 0.9rem 1.1rem;
    background: rgb(var(--paper-raised)); }
  .doc-main { flex: 1 1 320px; min-width: 0; }
  .doc-ref { display: block; font-family: ui-monospace, monospace; font-variant-numeric: tabular-nums;
    font-size: 0.72rem; color: rgb(var(--brass-deep)); text-transform: uppercase; margin-bottom: 0.2rem; }
  .doc-main h3 { font-family: Georgia, serif; font-weight: 600; font-size: 1rem; margin: 0 0 0.2rem; }
  .doc-meta { font-family: ui-monospace, monospace; font-variant-numeric: tabular-nums; font-size: 0.72rem;
    color: rgb(var(--muted)); white-space: nowrap; }
  .doc-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-left: auto; }
  .doc-btn { font-size: 0.76rem; padding: 0.4rem 0.75rem; border-radius: 6px; text-decoration: none;
    white-space: nowrap; border: 1px solid rgb(var(--line)); color: rgb(var(--ink)); }
  .doc-btn.primary { background: rgb(var(--brass) / 0.14); border-color: rgb(var(--brass));
    color: rgb(var(--brass-deep)); font-weight: 600; }
  .pending-box { border: 1px dashed rgb(var(--warn)); border-radius: 8px; padding: 1rem 1.2rem; margin-bottom: 3rem; }
  .pending-box h2 { font-family: Georgia, serif; font-size: 1.15rem; margin: 0 0 0.6rem; color: rgb(var(--warn)); }
  .pending-box ul { margin: 0; padding-left: 1.1rem; font-size: 0.88rem; }
  .pending-box li { margin-bottom: 0.5rem; }
  .pending-box .tag { font-family: ui-monospace, monospace; font-size: 0.7rem; color: rgb(var(--muted)); }
  footer.note { border-top: 1px solid rgb(var(--line)); padding-top: 1.4rem; margin-top: 1rem;
    font-size: 0.82rem; color: rgb(var(--muted)); max-width: 68ch; }
  @media (max-width: 640px) {
    .wrap { padding: 2rem 1.1rem 4rem; } .index { margin: 0 -1.1rem 1rem; padding: 0.7rem 1.1rem; }
    .doc-row { flex-direction: column; align-items: stretch; } .doc-actions { margin-left: 0; }
  }
</style>
</head>
<body>
<div class="wrap">

  <div class="masthead">
    <p class="eyebrow">Registro normativo · pesca professionale · auto-aggiornato</p>
    <h1>Pesca speciale e quote di cattura — decreti in vigore</h1>
    <p class="lede">Raccolta di provvedimenti nazionali (MASAF) e regionali (Veneto) su quote di cattura, fermo pesca, pesca ricreativa e pesche speciali lagunari. Le nuove voci rilevate dallo scraper vengono segnalate per revisione manuale, non categorizzate automaticamente.</p>
    <div class="meta-row">
      <span>Ultimo controllo MASAF: <strong><?= h($lastRunLabel) ?></strong></span>
      <span>Voci catalogate: <strong><?= $totalItems ?></strong></span>
      <span>Documenti PDF: <strong><?= $totalPdf ?></strong></span>
      <span>Da rivedere: <strong><?= count($pending) ?></strong></span>
    </div>
  </div>

  <nav class="index" aria-label="Indice categorie">
    <a class="chip" style="--chip-c: var(--brass)" href="#documenti"><span class="dot"></span>Documenti PDF</a>
    <?php foreach ($sections as $s): ?>
    <a class="chip" style="--chip-c: <?= h($s['color']) ?>" href="#<?= h($s['id']) ?>"><span class="dot"></span><?= h($s['title']) ?></a>
    <?php endforeach; ?>
  </nav>

  <div class="year-filter" role="group" aria-label="Filtra per anno">
    <span class="label">Filtra per anno</span>
    <button type="button" class="yr-chip" data-yr="all" aria-pressed="true">Tutti</button>
    <?php foreach ($years as $y): ?>
    <button type="button" class="yr-chip" data-yr="<?= h($y) ?>" aria-pressed="false"><?= $y === 'info' ? 'Info generali' : h($y) ?></button>
    <?php endforeach; ?>
  </div>

  <?php if (count($pending) > 0): ?>
  <div class="pending-box">
    <h2>⚠ <?= count($pending) ?> nuovi atti da rivedere</h2>
    <ul>
      <?php foreach ($pending as $p): ?>
      <li>
        <a href="<?= h($p['url']) ?>" target="_blank" rel="noopener"><?= h($p['title']) ?></a>
        <div class="tag">rilevato il <?= h($p['first_seen']) ?> · IDPagina <?= (int) $p['id'] ?> · non ancora verificato/categorizzato</div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <section class="category" id="documenti" style="--cat-c: var(--brass)">
    <div class="cat-head"><h2>Documenti scaricabili</h2><span class="count"><?= $totalPdf ?> PDF ufficiali MASAF</span></div>
    <p class="cat-desc">Download diretto, raggruppati come le categorie sotto.</p>
    <div class="doc-list">
      <?php foreach ($sections as $s): ?>
        <?php $pdfItems = array_filter($s['items'] ?? [], fn($i) => !empty($i['pdf'])); ?>
        <?php if (!$pdfItems): continue; endif; ?>
        <div class="doc-group-label"><?= h($s['title']) ?></div>
        <?php foreach ($pdfItems as $item): ?>
        <div class="doc-row" data-year="<?= h(item_year($item)) ?>">
          <div class="doc-main">
            <span class="doc-ref"><?= h($item['ref']) ?></span>
            <h3><?= h($item['title']) ?></h3>
          </div>
          <span class="doc-meta"><?= h($item['size'] ?? '') ?></span>
          <div class="doc-actions">
            <a class="doc-btn primary" href="<?= h($item['pdf']) ?>">Scarica PDF</a>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  </section>

  <?php foreach ($sections as $s): ?>
  <section class="category" id="<?= h($s['id']) ?>" style="--cat-c: <?= h($s['color']) ?>">
    <div class="cat-head"><h2><?= h($s['title']) ?></h2><span class="count"><?= count($s['items'] ?? []) ?> voci</span></div>
    <p class="cat-desc"><?= h($s['desc']) ?></p>
    <div class="cards">
      <?php foreach ($s['items'] ?? [] as $item): ?>
        <?php $linkUrl = $item['masaf_id'] ? 'https://www.masaf.gov.it/flex/cm/pages/ServeBLOB.php/L/IT/IDPagina/' . (int) $item['masaf_id'] : ($item['external_url'] ?? '#'); ?>
      <a class="card" data-year="<?= h(item_year($item)) ?>" href="<?= h($linkUrl) ?>" target="_blank" rel="noopener">
        <span class="ref"><?= h($item['ref']) ?></span>
        <h3><?= h($item['title']) ?></h3>
        <p><?= h($item['desc']) ?></p>
        <span class="source"><?= h($item['size'] ?? '') ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>

  <footer class="note">
    Pagina generata dinamicamente da <code>data/catalog.json</code> (curato e verificato a mano) e <code>data/known.json</code>
    (aggiornato da <code>scraper.php</code>, eseguito periodicamente via Task Scheduler). Ultimo controllo dell'indice MASAF:
    <strong><?= h($lastRunLabel) ?></strong>. Le voci "da rivedere" sono rilevate automaticamente ma non categorizzate:
    richiedono verifica manuale del testo del decreto prima di essere aggiunte al catalogo.
  </footer>

</div>

<script>
(function () {
  var yrButtons = document.querySelectorAll(".yr-chip");
  var items = document.querySelectorAll("[data-year]");
  var sections = document.querySelectorAll("section.category");
  yrButtons.forEach(function (btn) {
    btn.addEventListener("click", function () {
      yrButtons.forEach(function (b) { b.setAttribute("aria-pressed", "false"); });
      btn.setAttribute("aria-pressed", "true");
      var yr = btn.getAttribute("data-yr");
      items.forEach(function (el) {
        el.style.display = (yr === "all" || el.getAttribute("data-year") === yr) ? "" : "none";
      });
      sections.forEach(function (sec) {
        var tracked = sec.querySelectorAll("[data-year]");
        if (!tracked.length) return;
        var anyVisible = Array.prototype.some.call(tracked, function (c) { return c.style.display !== "none"; });
        sec.style.display = anyVisible ? "" : "none";
      });
    });
  });
})();
</script>
</body>
</html>
