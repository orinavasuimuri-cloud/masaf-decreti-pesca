<?php
declare(strict_types=1);

/**
 * Anteprima affiancata dei temi (assets/themes.css).
 *
 * Ogni tema è reso su un riquadro con data-theme e contenuti veri presi da
 * data/catalog.json e data/news.json: su contenuti finti le differenze fra
 * temi si vedono male, perché è la densità reale dei testi a metterle in crisi.
 * Il markup delle schede replica quello di registro.php e news.php.
 */

require_once __DIR__ . '/lib/theme.php';
require_once __DIR__ . '/lib/news_normalize.php';
require_once __DIR__ . '/lib/news_store.php';

date_default_timezone_set('Europe/Rome');

$dataDir  = __DIR__ . '/data';
$catalog  = json_decode((string) @file_get_contents($dataDir . '/catalog.json'), true) ?? ['sections' => []];
$store    = news_store_load($dataDir . '/news.json');
$srcConf  = json_decode((string) @file_get_contents($dataDir . '/news_sources.json'), true) ?? ['sources' => []];

$sources = [];
foreach ($srcConf['sources'] ?? [] as $s) {
    $sources[$s['id']] = $s;
}

function h(int|string|null $s): string {
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

// Stesso criterio di registro.php: il titolo dell'allegato si antepone alla
// descrizione solo quando aggiunge informazione.
function allegato_desc(array $a): string {
    $titolo = trim($a['titolo'] ?? '');
    $desc   = trim($a['desc'] ?? '');
    if ($desc === '') { return $titolo; }
    if ($titolo === '') { return $desc; }
    $strip = fn(string $s): string => strtolower((string) preg_replace('/[^a-z0-9]/i', '', $s));
    return str_contains($strip($desc), $strip($titolo)) ? $desc : $titolo . ' — ' . $desc;
}

/**
 * Campione di sezioni per l'anteprima: le prime due con almeno una scheda,
 * troncate a due schede ciascuna. Serve mostrare due colori di categoria
 * diversi — è lì che i temi si comportano in modo differente — senza
 * ristampare l'intero registro cinque volte.
 */
$sample = [];
foreach ($catalog['sections'] ?? [] as $s) {
    if (empty($s['items'])) { continue; }
    $s['items'] = array_slice($s['items'], 0, 2);
    $sample[] = $s;
    if (count($sample) === 2) { break; }
}
$sampleNews = array_slice($store['items'] ?? [], 0, 3);

// ?solo=<id> mostra un solo tema, senza testata né indice: serve a guardarne
// uno a tutta pagina (e a catturarne uno schermo) senza il confronto intorno.
// Fail-closed: qualunque valore non presente in THEMES torna alla vista completa.
$only = null;
if (isset($_GET['solo']) && is_string($_GET['solo']) && array_key_exists($_GET['solo'], THEMES)) {
    $only = $_GET['solo'];
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>Temi — anteprima</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/themes.css">
<style>
  /* Solo per questa pagina: i riquadri di anteprima. Il tema è applicato al
     riquadro, non a <html>, quindi carta e inchiostro vanno riportati qui —
     su <body> li mette style.css una volta sola. */
  /* background-color e non la scorciatoia background: quella azzererebbe il
     background-image dei temi che ne hanno uno (il reticolo di "nautica"). */
  .theme-frame { background-color: rgb(var(--paper)); color: rgb(var(--ink));
    border: 1px solid rgb(var(--line)); border-radius: 10px;
    padding: 1.6rem 1.7rem 1.9rem; margin: 0 0 2.6rem; overflow: hidden; }
  .theme-bar { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.8rem;
    margin: 0 0 1.4rem; padding-bottom: 0.7rem; border-bottom: 1px solid rgb(var(--line)); }
  .theme-bar h2 { font-family: Georgia, serif; font-size: 1.3rem; margin: 0; }
  .theme-bar .tid { font-family: ui-monospace, monospace; font-size: 0.72rem; color: rgb(var(--muted)); }
  .theme-bar .use { margin-left: auto; font: inherit; font-size: 0.76rem; font-weight: 600;
    cursor: pointer; padding: 0.35rem 0.8rem; border-radius: 6px; white-space: nowrap;
    background: rgb(var(--brass) / 0.14); border: 1px solid rgb(var(--brass)); color: rgb(var(--brass-deep)); }
  .theme-bar .use:hover { background: rgb(var(--brass) / 0.26); }
  .theme-bar .use[data-active="1"] { background: rgb(var(--brass)); color: rgb(var(--paper)); }
  .theme-note { font-size: 0.88rem; color: rgb(var(--muted)); margin: 0 0 1.4rem; max-width: 88ch; }
  .theme-jump { display: flex; flex-wrap: wrap; gap: 0.5rem; margin: 0 0 2rem; }
  .preview-sub { font-family: ui-monospace, monospace; font-size: 0.7rem; letter-spacing: 0.1em;
    text-transform: uppercase; color: rgb(var(--muted)); margin: 1.8rem 0 0.8rem; }
</style>
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <a href="index.php">★ Prima pagina</a><a href="registro.php">Registro decreti</a><a href="news.php">News →</a><a href="bandi.php">Bandi →</a>
    <?php if ($only !== null): ?><a href="temi.php">↔ confronta tutti i temi</a><?php endif; ?>
  </div>

  <?php if ($only === null): ?>
  <div class="masthead">
    <p class="eyebrow">Aspetto del sito · anteprima con contenuti reali</p>
    <h1>Temi</h1>
    <p class="lede">Ogni riquadro mostra le stesse schede di decreto e le stesse notizie con un tema
      diverso. La scelta si applica a registro, news e bandi e resta memorizzata nel browser;
      «Originale» è la resa attuale, invariata.</p>
  </div>

  <nav class="theme-jump" aria-label="Vai al tema">
    <?php foreach (THEMES as $id => $label): ?>
    <a class="chip" style="--chip-c: 156 122 46" href="#t-<?= h($id) ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <?php
  $note = [
      'default'  => 'La resa attuale: fondo verde chiaro, schede uniformi, nessun movimento. Riferimento per il confronto.',
      'laguna'   => 'Stessa famiglia di colori, ma con profondità: schede su carta più chiara, velo del colore di categoria in alto, ombra al passaggio del mouse, testata su fondo proprio.',
      'nautica'  => 'Blu notte con reticolo da carta nautica, accento ciano, schede a spigolo vivo con squadretta d’angolo e numero di decreto in monospazio riquadrato.',
      'gazzetta' => 'Impaginato da quotidiano: carta avorio, titoli serif più grandi, un solo accento rosso, schede senza cornice separate da filetti. Il colore di categoria resta come pallino sulla sigla.',
      'molo'     => 'Scuro caldo da applicazione: nessuna cornice, schede arrotondate su ombra, bottone di download pieno, categoria e fonte come pastiglia colorata.',
  ];
  foreach (THEMES as $id => $label):
    if ($only !== null && $id !== $only) { continue; }
    $themeAttr = $id === 'default' ? '' : ' data-theme="' . h($id) . '"';
  ?>
  <section class="theme-frame" id="t-<?= h($id) ?>"<?= $themeAttr ?>>
    <div class="theme-bar">
      <h2><?= h($label) ?></h2>
      <span class="tid"><?= $id === 'default' ? 'nessun data-theme' : 'data-theme="' . h($id) . '"' ?></span>
      <button type="button" class="use" data-theme-id="<?= h($id) ?>">Usa questo tema</button>
    </div>
    <p class="theme-note"><?= h($note[$id] ?? '') ?></p>

    <div class="meta-row">
      <span>Ultimo controllo MASAF: <strong>03/08/2026 09:14</strong></span>
      <span>Voci catalogate: <strong>40</strong></span>
      <span>Documenti PDF: <strong>52</strong></span>
    </div>

    <?php foreach ($sample as $s): ?>
    <section class="category" style="--cat-c: <?= h($s['color']) ?>">
      <div class="cat-head"><h2><?= h($s['title']) ?></h2><span class="count"><?= count($s['items']) ?> voci</span></div>
      <p class="cat-desc"><?= h($s['desc'] ?? '') ?></p>
      <div class="cards">
        <?php foreach ($s['items'] as $item): ?>
        <article class="card">
          <span class="ref"><?= h($item['ref']) ?></span>
          <h3><a href="#"><?= h($item['title']) ?></a></h3>
          <p><?= h($item['desc']) ?></p>
          <div class="foot">
            <div class="foot-main">
              <?php if (!empty($item['pdf'])): ?>
              <a class="dl" href="#">↓ Scarica PDF</a>
              <span class="size"><?= h($item['size'] ?? '') ?></span>
              <?php else: ?>
              <span class="source"><?= h($item['size'] ?? '') ?></span>
              <?php endif; ?>
            </div>
            <?php if (!empty($item['allegati'])): ?>
            <ul class="allegati">
              <?php foreach (array_slice($item['allegati'], 0, 2) as $a): ?>
              <li>
                <span class="all-desc"><?= h(allegato_desc($a)) ?></span>
                <a class="dl" href="#">Scarica PDF</a>
                <span class="size"><?= h($a['size'] ?? '') ?></span>
              </li>
              <?php endforeach; ?>
            </ul>
            <?php endif; ?>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>

    <?php if ($sampleNews): ?>
    <p class="preview-sub">Pagina news</p>
    <div class="news-list">
      <?php foreach ($sampleNews as $n): ?>
        <?php $src = $sources[$n['source']] ?? ['label' => $n['source'] ?? 'fonte', 'color' => '107 100 89']; ?>
      <article class="news-item" style="--src-c: <?= h($src['color']) ?>">
        <div class="news-head">
          <span class="news-badge"><?= h($src['label']) ?></span>
          <span class="news-date"><?= h(news_date_label($n['date'] ?? null)) ?></span>
        </div>
        <h3><a href="#"><?= h($n['title'] ?? '') ?></a></h3>
        <?php if (!empty($n['summary'])): ?><p><?= h($n['summary']) ?></p><?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>

  <footer class="note">
    I temi sono definiti in <code>assets/themes.css</code> come sovrascritture delle variabili di
    <code>assets/style.css</code>: aggiungerne uno significa aggiungere un blocco
    <code>[data-theme="…"]</code> e una voce alla costante <code>THEMES</code> in <code>lib/theme.php</code>.
    La preferenza è salvata in <code>localStorage</code> sotto la chiave <code>tema</code>.
  </footer>

</div>

<script>
(function () {
  function attivo() {
    try { return localStorage.getItem('tema') || 'default'; } catch (e) { return 'default'; }
  }
  var bottoni = document.querySelectorAll('.use');
  function segna() {
    var cur = attivo();
    bottoni.forEach(function (b) {
      var mio = b.dataset.themeId === cur;
      b.dataset.active = mio ? '1' : '0';
      b.textContent = mio ? '✓ Tema in uso' : 'Usa questo tema';
    });
  }
  bottoni.forEach(function (b) {
    b.addEventListener('click', function () {
      try { localStorage.setItem('tema', b.dataset.themeId); } catch (e) { /* preferenza non memorizzabile */ }
      segna();
    });
  });
  segna();
})();
</script>
</body>
</html>
