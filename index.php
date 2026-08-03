<?php
declare(strict_types=1);

/**
 * Prima pagina in forma di quotidiano: gli stessi dati di registro.php, news.php
 * e bandi.php impaginati per gerarchia invece che per griglia uniforme.
 *
 * Le tre pagine esistenti restano quelle di consultazione (ricerca, filtri,
 * elenco completo); questa è la vetrina: cosa è uscito, cosa scade, cosa
 * aspetta revisione. Non introduce dati propri, solo un ordinamento diverso.
 */

require_once __DIR__ . '/lib/news_normalize.php';
require_once __DIR__ . '/lib/news_store.php';
require_once __DIR__ . '/lib/bandi_normalize.php';
require_once __DIR__ . '/lib/bandi_store.php';
require_once __DIR__ . '/lib/theme.php';

date_default_timezone_set('Europe/Rome');

$dataDir = __DIR__ . '/data';
$catalog = json_decode((string) @file_get_contents($dataDir . '/catalog.json'), true) ?? ['sections' => []];
$known   = json_decode((string) @file_get_contents($dataDir . '/known.json'), true) ?? ['items' => []];
$newsSt  = news_store_load($dataDir . '/news.json');
$srcConf = json_decode((string) @file_get_contents($dataDir . '/news_sources.json'), true) ?? ['sources' => []];

// Come in bandi.php: un archivio illeggibile può fermare il fetcher, mai la
// pagina. Qui il degrado è ancora più silenzioso, perché i bandi sono solo
// una spalla: se mancano, il riquadro non viene stampato.
try {
    $bandiSt = bandi_store_load($dataDir . '/bandi.json');
} catch (RuntimeException $e) {
    $bandiSt = bandi_store_empty();
}

$sources = [];
foreach ($srcConf['sources'] ?? [] as $s) {
    $sources[$s['id']] = $s;
}

function h(int|string|null $s): string {
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Data di un decreto, estratta dal campo 'ref' ("D.D. n. 156886 · 01/04/2026").
 * Serve a ordinare per uscita: il catalogo è organizzato per specie, non
 * cronologicamente, e una prima pagina senza il criterio "cosa è uscito per
 * ultimo" non è una prima pagina. Le voci senza data (informative, portali)
 * tornano null e finiscono in fondo, non in apertura.
 */
function ref_data(string $ref): ?string {
    if (preg_match('#\b(\d{2})/(\d{2})/(\d{4})\b#', $ref, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    return null;
}

function data_lunga(string $iso): string {
    $mesi = [1 => 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno',
             'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
    $t = strtotime($iso);
    if ($t === false) { return $iso; }
    return (int) date('j', $t) . ' ' . $mesi[(int) date('n', $t)] . ' ' . date('Y', $t);
}

function scheda_url(array $item): string {
    if (!empty($item['masaf_id'])) {
        return 'https://www.masaf.gov.it/flex/cm/pages/ServeBLOB.php/L/IT/IDPagina/' . (int) $item['masaf_id'];
    }
    return $item['external_url'] ?? 'registro.php';
}

// --- decreti in ordine di uscita ------------------------------------------
$decreti = [];
$totPdf = 0;
$totAllegati = 0;
foreach ($catalog['sections'] ?? [] as $s) {
    foreach ($s['items'] ?? [] as $i) {
        $i['_sez']   = $s['title'];
        $i['_sezId'] = $s['id'];
        $i['_col']   = $s['color'];
        $i['_data']  = ref_data($i['ref'] ?? '');
        $decreti[] = $i;
        if (!empty($i['pdf'])) { $totPdf++; }
        $totAllegati += count($i['allegati'] ?? []);
    }
}
// Ordine decrescente con le voci senza data in coda: usort non è stabile, ma
// qui non serve, l'unico criterio è la data.
usort($decreti, function (array $a, array $b): int {
    if ($a['_data'] === $b['_data']) { return 0; }
    if ($a['_data'] === null) { return 1; }
    if ($b['_data'] === null) { return -1; }
    return strcmp($b['_data'], $a['_data']);
});

$lead   = $decreti[0] ?? null;
$spalle = array_slice($decreti, 1, 2);
// Coda dell'apertura: senza, la colonna centrale finisce molto prima della
// spalla destra e resta mezza pagina bianca sotto il pezzo forte.
$altri  = array_slice($decreti, 3, 6);

// --- notizie ---------------------------------------------------------------
$news      = $newsSt['items'] ?? [];
$ticker    = array_slice($news, 0, 10);
$ultimora  = array_slice($news, 0, 6);
$grigliaNw = array_slice($news, 6, 8);

// --- bandi in scadenza -----------------------------------------------------
$oggi = date('Y-m-d');
$inScadenza = [];
foreach ($bandiSt['items'] ?? [] as $b) {
    if (bandi_stato($b['scadenza'] ?? null, (bool) ($b['terminato_in_fonte'] ?? false), $oggi) !== 'aperto') {
        continue;
    }
    if (empty($b['scadenza'])) { continue; }
    $inScadenza[] = $b;
}
usort($inScadenza, fn(array $a, array $b): int => strcmp($a['scadenza'], $b['scadenza']));
$inScadenza = array_slice($inScadenza, 0, 5);

// --- da rivedere -----------------------------------------------------------
$pending = array_values(array_filter($known['items'] ?? [], fn($i) => ($i['status'] ?? '') === 'pending_review'));
usort($pending, fn($a, $b) => strcmp($b['first_seen'] ?? '', $a['first_seen'] ?? ''));
$pending = array_slice($pending, 0, 3);

$lastRun = $known['_meta']['last_run'] ?? null;
$lastRunLabel = $lastRun ? date('d/m/Y H:i', (int) strtotime($lastRun)) : 'mai eseguito';
$sezioni = array_values(array_filter($catalog['sections'] ?? [], fn($s) => !empty($s['items'])));
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>La Gazzetta della Pesca — prima pagina</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/giornale.css">
<?php render_theme_head(); ?>
</head>
<body>
<div class="gz">

  <div class="gz-top">
    <span class="data"><?= h(data_lunga($oggi)) ?></span>
    <span class="sep">|</span>
    <span>Ultimo controllo MASAF: <?= h($lastRunLabel) ?></span>
    <nav>
      <a href="registro.php">Registro decreti</a>
      <a href="news.php">News</a>
      <a href="bandi.php">Bandi</a>
      <a href="temi.php">Temi</a>
    </nav>
    <?php render_theme_switcher(); ?>
  </div>

  <?php if ($ticker): ?>
  <div class="gz-ticker">
    <span class="etich">Ultim'ora</span>
    <div class="pista">
      <div class="nastro">
        <?php /* Due passate sullo stesso elenco: la seconda copia è quella che
                 entra da destra mentre la prima esce, e tiene il nastro pieno.
                 aria-hidden sulla copia per non far leggere due volte i titoli. */ ?>
        <?php for ($giro = 0; $giro < 2; $giro++): ?>
          <?php foreach ($ticker as $n): ?>
            <?php $src = $sources[$n['source']] ?? ['label' => $n['source'] ?? '', 'color' => '107 100 89']; ?>
          <a href="<?= h($n['url'] ?? '#') ?>" target="_blank" rel="noopener"
             style="--src-c: <?= h($src['color']) ?>"<?= $giro ? ' aria-hidden="true" tabindex="-1"' : '' ?>>
            <span class="fonte"><?= h($src['label']) ?></span><?= h($n['title'] ?? '') ?>
          </a>
          <?php endforeach; ?>
        <?php endfor; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <header class="gz-testata">
    <p class="occhiello">Registro normativo · pesca professionale · aggiornato in automatico</p>
    <h1>La Gazzetta della Pesca</h1>
    <p class="sottotitolo">Quote di cattura, fermi, pesche speciali, bandi e rassegna stampa —
      provvedimenti nazionali MASAF e regionali, raccolti e verificati.</p>
    <div class="gz-filetto"></div>
  </header>

  <nav class="gz-nav" aria-label="Categorie">
    <a class="primo" href="registro.php">Tutto il registro</a>
    <?php foreach ($sezioni as $s): ?>
    <a href="#s-<?= h($s['id']) ?>" style="--voce-c: <?= h($s['color']) ?>"><?= h($s['title']) ?></a>
    <?php endforeach; ?>
    <a href="bandi.php" style="--voce-c: 156 122 46">Bandi</a>
  </nav>

  <div class="gz-apertura">

    <section class="gz-ultimora" aria-labelledby="ul-tit">
      <h2 class="gz-colonna-tit" id="ul-tit">Dal settore</h2>
      <ol>
        <?php foreach ($ultimora as $n): ?>
          <?php $src = $sources[$n['source']] ?? ['label' => $n['source'] ?? '', 'color' => '107 100 89']; ?>
        <li style="--src-c: <?= h($src['color']) ?>">
          <div>
            <h4><a href="<?= h($n['url'] ?? '#') ?>" target="_blank" rel="noopener"><?= h($n['title'] ?? '') ?></a></h4>
            <div class="meta">
              <span class="fonte"><?= h($src['label']) ?></span>
              <span><?= h(news_date_label($n['date'] ?? null)) ?></span>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
    </section>

    <?php if ($lead !== null): ?>
    <main class="gz-lead" style="--voce-c: <?= h($lead['_col']) ?>">
      <span class="gz-occhiello"><?= h($lead['_sez']) ?></span>
      <h2><a href="<?= h(scheda_url($lead)) ?>" target="_blank" rel="noopener"><?= h($lead['title']) ?></a></h2>
      <p class="sommario"><?= h($lead['desc']) ?></p>
      <div class="gz-firma">
        <span><?= h($lead['ref']) ?></span>
        <?php if (!empty($lead['pdf'])): ?>
        <a class="dl" href="<?= h($lead['pdf']) ?>">↓ Scarica PDF</a>
        <?php endif; ?>
        <?php if (!empty($lead['allegati'])): ?>
        <span><?= count($lead['allegati']) ?> allegati</span>
        <?php endif; ?>
      </div>

      <?php if ($spalle): ?>
      <div class="gz-spalle">
        <?php foreach ($spalle as $sp): ?>
        <article style="--voce-c: <?= h($sp['_col']) ?>">
          <span class="gz-occhiello"><?= h($sp['_sez']) ?></span>
          <h3><a href="<?= h(scheda_url($sp)) ?>" target="_blank" rel="noopener"><?= h($sp['title']) ?></a></h3>
          <p><?= h($sp['desc']) ?></p>
        </article>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($altri): ?>
      <section class="gz-altri">
        <h3 class="gz-colonna-tit">Altri provvedimenti recenti</h3>
        <ul>
          <?php foreach ($altri as $a): ?>
          <li style="--voce-c: <?= h($a['_col']) ?>">
            <span class="gz-occhiello"><?= h($a['_sez']) ?></span>
            <a href="<?= h(scheda_url($a)) ?>" target="_blank" rel="noopener"><?= h($a['title']) ?></a>
            <span class="meta"><?= h($a['ref']) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>
    </main>
    <?php endif; ?>

    <aside class="gz-side">
      <?php if ($inScadenza): ?>
      <div class="gz-widget">
        <h2 class="gz-colonna-tit">Bandi in scadenza</h2>
        <ul>
          <?php foreach ($inScadenza as $b): ?>
          <?php /* Titolo intero e link alla fonte, come in bandi.php: la
                   riduzione a tre righe la fa il CSS, che taglia a fine riga.
                   bandi_titolo_da_scopo() qui non va: serve a ricavare un
                   titolo dal campo 'scopo' quando manca, non ad accorciare
                   un titolo che c'è già. */ ?>
          <li>
            <span class="quando">scade il <?= h(news_date_label($b['scadenza'], 'd/m/Y', '—')) ?></span>
            <a class="clamp" href="<?= h($b['url_fonte'] ?? 'bandi.php') ?>" target="_blank" rel="noopener"><?= h($b['titolo'] ?? '') ?></a>
            <?php if (!empty($b['regioni'])): ?>
            <div class="reg"><?= h(implode(', ', $b['regioni'])) ?></div>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <?php if ($pending): ?>
      <div class="gz-widget">
        <h2 class="gz-colonna-tit">In attesa di verifica</h2>
        <ul>
          <?php foreach ($pending as $p): ?>
          <li>
            <span class="quando">rilevato il <?= h(news_date_label($p['first_seen'] ?? null, 'd/m/Y', '—')) ?></span>
            <a class="clamp" href="<?= h($p['url'] ?? '#') ?>" target="_blank" rel="noopener"><?= h($p['title'] ?? '') ?></a>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <div class="gz-widget">
        <h2 class="gz-colonna-tit">In numeri</h2>
        <div class="gz-numeri">
          <div><strong><?= count($decreti) ?></strong><span>voci a catalogo</span></div>
          <div><strong><?= $totPdf + $totAllegati ?></strong><span>documenti PDF</span></div>
          <div><strong><?= $totAllegati ?></strong><span>allegati</span></div>
          <div><strong><?= count($sezioni) ?></strong><span>categorie</span></div>
        </div>
      </div>
    </aside>

  </div>

  <?php if ($grigliaNw): ?>
  <section class="gz-sezione">
    <div class="gz-sezione-tit">
      <h2>Rassegna stampa</h2>
      <a class="piu" href="news.php">Tutte le notizie →</a>
    </div>
    <div class="gz-griglia">
      <?php foreach ($grigliaNw as $n): ?>
        <?php $src = $sources[$n['source']] ?? ['label' => $n['source'] ?? '', 'color' => '107 100 89']; ?>
      <article style="--voce-c: <?= h($src['color']) ?>">
        <span class="gz-occhiello"><?= h($src['label']) ?></span>
        <h3><a href="<?= h($n['url'] ?? '#') ?>" target="_blank" rel="noopener"><?= h($n['title'] ?? '') ?></a></h3>
        <?php if (!empty($n['summary'])): ?><p><?= h($n['summary']) ?></p><?php endif; ?>
        <span class="quando"><?= h(news_date_label($n['date'] ?? null)) ?></span>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php foreach ($sezioni as $s): ?>
    <?php
      // Dentro il blocco l'ordine torna cronologico: il catalogo elenca per
      // affinità di materia, che in una pagina di apertura non dice nulla.
      $voci = $s['items'];
      usort($voci, function (array $a, array $b): int {
          $da = ref_data($a['ref'] ?? '');
          $db = ref_data($b['ref'] ?? '');
          if ($da === $db) { return 0; }
          if ($da === null) { return 1; }
          if ($db === null) { return -1; }
          return strcmp($db, $da);
      });
      $primo = array_shift($voci);
    ?>
  <section class="gz-blocco" id="s-<?= h($s['id']) ?>" style="--voce-c: <?= h($s['color']) ?>">
    <div class="gz-blocco-tit">
      <h2><?= h($s['title']) ?></h2>
      <span class="conta"><?= count($s['items']) ?> voci</span>
    </div>
    <p class="gz-cat-desc"><?= h($s['desc'] ?? '') ?></p>
    <div class="gz-blocco-corpo">
      <article class="gz-primo">
        <span class="gz-occhiello"><?= h($primo['ref'] ?? '') ?></span>
        <h3><a href="<?= h(scheda_url($primo)) ?>" target="_blank" rel="noopener"><?= h($primo['title'] ?? '') ?></a></h3>
        <p><?= h($primo['desc'] ?? '') ?></p>
        <div class="gz-firma">
          <?php if (!empty($primo['pdf'])): ?>
          <a class="dl" href="<?= h($primo['pdf']) ?>">↓ Scarica PDF</a>
          <?php else: ?>
          <span><?= h($primo['size'] ?? '') ?></span>
          <?php endif; ?>
          <?php if (!empty($primo['allegati'])): ?>
          <span><?= count($primo['allegati']) ?> allegati</span>
          <?php endif; ?>
        </div>
      </article>
      <?php if ($voci): ?>
      <ul class="gz-elenco">
        <?php foreach ($voci as $v): ?>
        <li>
          <span class="meta"><?= h($v['ref'] ?? '') ?></span>
          <h4><a href="<?= h(scheda_url($v)) ?>" target="_blank" rel="noopener"><?= h($v['title'] ?? '') ?></a></h4>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </section>
  <?php endforeach; ?>

  <footer>
    <div class="gz-foot">
      <div>
        <h3>Categorie</h3>
        <ul>
          <?php foreach (array_slice($sezioni, 0, 6) as $s): ?>
          <li><a href="#s-<?= h($s['id']) ?>"><?= h($s['title']) ?></a> <span class="conta"><?= count($s['items']) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div>
        <h3>Ancora categorie</h3>
        <ul>
          <?php foreach (array_slice($sezioni, 6) as $s): ?>
          <li><a href="#s-<?= h($s['id']) ?>"><?= h($s['title']) ?></a> <span class="conta"><?= count($s['items']) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div>
        <h3>Fonti della rassegna</h3>
        <ul>
          <?php foreach ($sources as $s): ?>
          <li><?= h($s['label']) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div>
        <h3>Pagine</h3>
        <ul>
          <li><a href="registro.php">Registro dei decreti</a></li>
          <li><a href="news.php">Rassegna stampa</a></li>
          <li><a href="bandi.php">Bandi per regione</a></li>
          <li><a href="temi.php">Aspetto del sito</a></li>
        </ul>
      </div>
    </div>
    <p class="gz-colophon">
      Pagina composta da <code>data/catalog.json</code> (curato a mano), <code>data/news.json</code> e
      <code>data/bandi.json</code> (aggiornati dai fetcher via Task Scheduler). L'ordine di apertura segue la
      data dei provvedimenti, non la loro importanza: nessuna redazione decide cosa va in prima.
      Titoli ed estratti della rassegna appartengono alle testate indicate.
    </p>
  </footer>

</div>
</body>
</html>
