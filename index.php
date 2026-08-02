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

// Testo su cui lavora la ricerca client-side: numero di decreto, titolo,
// descrizione e categoria di appartenenza (così "tonno" o "gamberi" trovano
// anche le voci che nominano la specie solo nel titolo della sezione).
function search_blob(array $item, string $sectionTitle = ''): string {
    return trim(implode(' ', array_filter([
        $item['ref'] ?? '',
        $item['title'] ?? '',
        $item['desc'] ?? '',
        $sectionTitle,
    ])));
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
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap">

  <div class="topbar"><a href="news.php">News dal mondo della pesca →</a><a href="bandi.php">Bandi per regione →</a></div>

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
    <?php foreach ($sections as $s): ?>
    <a class="chip" style="--chip-c: <?= h($s['color']) ?>" href="#<?= h($s['id']) ?>"><span class="dot"></span><?= h($s['title']) ?></a>
    <?php endforeach; ?>
    <a class="chip" style="--chip-c: var(--brass)" href="#documenti"><span class="dot"></span>Documenti PDF</a>
  </nav>

  <div class="searchbar">
    <label class="sr-only" for="q">Cerca nel registro</label>
    <svg class="search-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M16.5 16.5 21 21"/></svg>
    <input type="search" id="q" autocomplete="off" placeholder="Cerca: numero di decreto, specie, parola chiave…">
    <button type="button" class="search-clear" id="q-clear" hidden aria-label="Cancella la ricerca">✕</button>
    <p class="search-status" id="q-status" role="status" aria-live="polite"></p>
  </div>

  <div class="year-filter" role="group" aria-label="Filtra per anno">
    <span class="label">Filtra per anno</span>
    <button type="button" class="yr-chip" data-yr="all" aria-pressed="true">Tutti</button>
    <?php foreach ($years as $y): ?>
    <button type="button" class="yr-chip" data-yr="<?= h($y) ?>" aria-pressed="false"><?= $y === 'info' ? 'Info generali' : h($y) ?></button>
    <?php endforeach; ?>
  </div>

  <div class="no-results" id="no-results" hidden>Nessuna voce corrisponde ai filtri attivi.</div>

  <?php if (count($pending) > 0): ?>
  <div class="pending-box">
    <h2>⚠ <?= count($pending) ?> nuovi atti da rivedere</h2>
    <ul>
      <?php foreach ($pending as $p): ?>
      <li data-search="<?= h(search_blob($p, 'da rivedere')) ?>">
        <a href="<?= h($p['url']) ?>" target="_blank" rel="noopener"><?= h($p['title']) ?></a>
        <div class="tag">rilevato il <?= h($p['first_seen']) ?> · IDPagina <?= (int) $p['id'] ?> · non ancora verificato/categorizzato</div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php foreach ($sections as $s): ?>
  <section class="category" id="<?= h($s['id']) ?>" style="--cat-c: <?= h($s['color']) ?>">
    <div class="cat-head"><h2><?= h($s['title']) ?></h2><span class="count"><?= count($s['items'] ?? []) ?> voci</span></div>
    <p class="cat-desc"><?= h($s['desc']) ?></p>
    <div class="cards">
      <?php foreach ($s['items'] ?? [] as $item): ?>
        <?php $linkUrl = $item['masaf_id'] ? 'https://www.masaf.gov.it/flex/cm/pages/ServeBLOB.php/L/IT/IDPagina/' . (int) $item['masaf_id'] : ($item['external_url'] ?? '#'); ?>
      <a class="card" data-year="<?= h(item_year($item)) ?>" data-search="<?= h(search_blob($item, $s['title'] ?? '')) ?>" href="<?= h($linkUrl) ?>" target="_blank" rel="noopener">
        <span class="ref"><?= h($item['ref']) ?></span>
        <h3><?= h($item['title']) ?></h3>
        <p><?= h($item['desc']) ?></p>
        <span class="source"><?= h($item['size'] ?? '') ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>

  <section class="category" id="documenti" style="--cat-c: var(--brass)">
    <div class="cat-head"><h2>Documenti scaricabili</h2><span class="count"><?= $totalPdf ?> PDF ufficiali MASAF</span></div>
    <p class="cat-desc">Download diretto, raggruppati come le categorie sopra.</p>
    <div class="doc-list">
      <?php foreach ($sections as $s): ?>
        <?php $pdfItems = array_filter($s['items'] ?? [], fn($i) => !empty($i['pdf'])); ?>
        <?php if (!$pdfItems): continue; endif; ?>
        <div class="doc-group">
        <div class="doc-group-label"><?= h($s['title']) ?></div>
        <?php foreach ($pdfItems as $item): ?>
        <div class="doc-row" data-year="<?= h(item_year($item)) ?>" data-search="<?= h(search_blob($item, $s['title'] ?? '')) ?>">
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
        </div>
      <?php endforeach; ?>
    </div>
  </section>

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
  var input = document.getElementById("q");
  var clearBtn = document.getElementById("q-clear");
  var statusEl = document.getElementById("q-status");
  var noResults = document.getElementById("no-results");
  var pendingBox = document.querySelector(".pending-box");
  var sections = document.querySelectorAll("section.category");
  var groups = document.querySelectorAll(".doc-group");
  var currentYear = "all";

  // Confronto senza accenti né maiuscole: "pesca speciale" trova "Pesca Speciale",
  // "gia" trova "già". Il blob è precalcolato una volta sola, non a ogni tasto.
  function norm(s) {
    return (s || "").toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
  }

  var records = Array.prototype.map.call(document.querySelectorAll("[data-search]"), function (el) {
    return { el: el, blob: norm(el.getAttribute("data-search")), year: el.getAttribute("data-year") };
  });

  function anyVisible(container) {
    var tracked = container.querySelectorAll("[data-search]");
    if (!tracked.length) return true;
    return Array.prototype.some.call(tracked, function (c) { return c.style.display !== "none"; });
  }

  function applyFilters() {
    var raw = input ? input.value.trim() : "";
    var terms = norm(raw).split(/\s+/).filter(Boolean);
    var visible = 0;

    records.forEach(function (r) {
      // Il filtro anno tocca solo le voci datate: gli atti da rivedere non lo sono.
      var okYear = !r.year || currentYear === "all" || r.year === currentYear;
      var okText = terms.every(function (t) { return r.blob.indexOf(t) !== -1; });
      var show = okYear && okText;
      r.el.style.display = show ? "" : "none";
      // Le voci senza anno restano visibili con un anno selezionato, ma non vanno
      // contate come "risultati" di quel filtro: gonfierebbero il totale e
      // impedirebbero al messaggio "nessun risultato" di comparire.
      if (show && (r.year || terms.length)) visible++;
    });

    groups.forEach(function (g) { g.style.display = anyVisible(g) ? "" : "none"; });
    sections.forEach(function (sec) { sec.style.display = anyVisible(sec) ? "" : "none"; });
    if (pendingBox) pendingBox.style.display = anyVisible(pendingBox) ? "" : "none";

    // Il border-top che separa i gruppi va tolto al primo gruppo ancora visibile,
    // altrimenti raddoppia il bordo del contenitore quando i precedenti sono filtrati.
    var seenGroup = false;
    groups.forEach(function (g) {
      var shown = g.style.display !== "none";
      g.classList.toggle("first-visible", shown && !seenGroup);
      if (shown) seenGroup = true;
    });

    if (noResults) noResults.hidden = visible > 0;
    if (clearBtn) clearBtn.hidden = raw === "";
    if (statusEl) {
      statusEl.textContent = (raw === "" && currentYear === "all")
        ? ""
        : visible + (visible === 1 ? " voce trovata" : " voci trovate");
    }
    // Su file:// (o URL non parsabili) replaceState può lanciare: la ricerca
    // deve continuare a funzionare anche senza sincronizzare l'indirizzo.
    try {
      var url = new URL(window.location.href);
      if (raw) { url.searchParams.set("q", raw); } else { url.searchParams.delete("q"); }
      window.history.replaceState(null, "", url);
    } catch (e) { /* nessuna sincronizzazione dell'URL */ }
  }

  yrButtons.forEach(function (btn) {
    btn.addEventListener("click", function () {
      yrButtons.forEach(function (b) { b.setAttribute("aria-pressed", "false"); });
      btn.setAttribute("aria-pressed", "true");
      currentYear = btn.getAttribute("data-yr");
      applyFilters();
    });
  });

  if (input) {
    input.addEventListener("input", applyFilters);
    input.addEventListener("keydown", function (e) {
      if (e.key === "Escape") { input.value = ""; applyFilters(); }
    });
  }
  if (clearBtn) {
    clearBtn.addEventListener("click", function () { input.value = ""; input.focus(); applyFilters(); });
  }

  // Ricerca condivisibile via ?q=... nell'URL
  try {
    var initial = new URL(window.location.href).searchParams.get("q");
    if (initial && input) { input.value = initial; }
  } catch (e) { /* si parte senza ricerca precompilata */ }
  applyFilters();
})();
</script>
</body>
</html>
