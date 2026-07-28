<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/news_store.php';

$dataDir = __DIR__ . '/data';
$store   = news_store_load($dataDir . '/news.json');
$config  = json_decode((string) @file_get_contents($dataDir . '/news_sources.json'), true) ?? ['sources' => []];

$sources = [];
foreach ($config['sources'] ?? [] as $s) {
    $sources[$s['id']] = $s;
}

$now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('c');

$stale = [];
foreach ($sources as $id => $s) {
    if (news_source_is_stale($store, $id, $now)) {
        $stale[] = $s['label'];
    }
}

$items = $store['items'];
$lastRun = $store['_meta']['last_run'] ?? null;
$lastRunLabel = $lastRun ? date('d/m/Y H:i', strtotime($lastRun)) : 'mai eseguito';

function h(int|string|null $s): string {
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>News — Il mondo della pesca in Italia</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap">

  <div class="topbar"><a href="index.php">← Registro decreti</a></div>

  <div class="masthead">
    <p class="eyebrow">Rassegna · pesca professionale · aggiornamento automatico</p>
    <h1>News dal mondo della pesca</h1>
    <p class="lede">Notizie da stampa di settore e fonti istituzionali, raccolte automaticamente.
      A differenza del registro dei decreti, questa pagina non è curata a mano: i contenuti
      appartengono alle rispettive testate e sono riportati con titolo, estratto e link all'originale.</p>
    <div class="meta-row">
      <span>Ultimo aggiornamento: <strong><?= h($lastRunLabel) ?></strong></span>
      <span>Notizie: <strong><?= count($items) ?></strong></span>
      <span>Fonti attive: <strong><?= count($sources) - count($stale) ?>/<?= count($sources) ?></strong></span>
    </div>
  </div>

  <?php if ($stale): ?>
  <div class="news-stale">
    ⚠ Nessun aggiornamento da oltre 48 ore da: <strong><?= h(implode(', ', $stale)) ?></strong>.
    Le notizie già raccolte restano consultabili.
  </div>
  <?php endif; ?>

  <div class="year-filter" role="group" aria-label="Filtra per fonte">
    <span class="label">Filtra per fonte</span>
    <button type="button" class="yr-chip" data-src="all" aria-pressed="true">Tutte</button>
    <?php foreach ($sources as $id => $s): ?>
    <button type="button" class="yr-chip" data-src="<?= h($id) ?>" aria-pressed="false"><?= h($s['label']) ?></button>
    <?php endforeach; ?>
  </div>

  <?php if (!$items): ?>
  <div class="news-empty">
    Nessuna notizia ancora raccolta. Esegui <code>php news_fetcher.php</code> per popolare la pagina.
  </div>
  <?php else: ?>
  <div class="news-list">
    <?php foreach ($items as $item): ?>
      <?php $src = $sources[$item['source']] ?? ['label' => $item['source'], 'color' => '107 100 89']; ?>
    <article class="news-item" data-src="<?= h($item['source']) ?>" style="--src-c: <?= h($src['color']) ?>">
      <div class="news-head">
        <span class="news-badge"><?= h($src['label']) ?></span>
        <span class="news-date"><?= h(date('d/m/Y', strtotime($item['date']))) ?></span>
      </div>
      <h3><a href="<?= h($item['url']) ?>" target="_blank" rel="noopener"><?= h($item['title']) ?></a></h3>
      <?php if (!empty($item['summary'])): ?>
      <p><?= h($item['summary']) ?></p>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <footer class="note">
    Pagina generata da <code>data/news.json</code>, aggiornato da <code>news_fetcher.php</code> ogni 6 ore
    via Task Scheduler. Si conservano al massimo 120 notizie o 90 giorni. Titoli, estratti e link
    appartengono alle testate indicate: per il testo integrale seguire il collegamento alla fonte.
  </footer>

</div>

<script>
(function () {
  var buttons = document.querySelectorAll(".yr-chip");
  var items = document.querySelectorAll(".news-item");
  buttons.forEach(function (btn) {
    btn.addEventListener("click", function () {
      buttons.forEach(function (b) { b.setAttribute("aria-pressed", "false"); });
      btn.setAttribute("aria-pressed", "true");
      var src = btn.getAttribute("data-src");
      items.forEach(function (el) {
        el.style.display = (src === "all" || el.getAttribute("data-src") === src) ? "" : "none";
      });
    });
  });
})();
</script>
</body>
</html>
