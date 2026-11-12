<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/accesso.php';
accesso_richiedi();

require_once __DIR__ . '/lib/searchbar.php';
require_once __DIR__ . '/lib/gazzetta_parser.php';
require_once __DIR__ . '/lib/archivio_pagina.php';
require_once __DIR__ . '/lib/refresh_ui.php';
require_once __DIR__ . '/lib/dinamica.php';

// date.timezone è UTC sul server: senza questo l'ora mostrata in "ultimo
// aggiornamento" sarebbe sfasata di due ore rispetto a quella reale.
date_default_timezone_set('Europe/Rome');

$dataDir = __DIR__ . '/data';

// Un archivio illeggibile degradava a vuoto senza dirlo: la coda "da rivedere"
// appariva sgombra e il curatore concludeva che non c'era nulla da guardare,
// mentre le voci c'erano e nessuno le vedeva. bandi.php dichiarava già il
// proprio guasto in pagina, questa non lo faceva per nessuno dei tre file.
$archiviGuasti = [];
$catalog  = archivio_pagina($dataDir . '/catalog.json',  ['sections' => []], $archiviGuasti);
$known    = archivio_pagina($dataDir . '/known.json',    ['items' => []],    $archiviGuasti);
$gazzetta = archivio_pagina($dataDir . '/gazzetta.json', ['items' => []],    $archiviGuasti);

$sections = $catalog['sections'] ?? [];

// Un atto messo in catalogo esce dalla coda anche se known.json (scritto dal
// server) lo segna ancora da rivedere: altrimenti comparirebbe due volte.
$idInCatalogo = [];
foreach ($sections as $section) {
    foreach ($section['items'] ?? [] as $item) {
        if (!empty($item['masaf_id'])) {
            $idInCatalogo[(int) $item['masaf_id']] = true;
        }
    }
}
$pending = array_filter($known['items'] ?? [], fn($i) => ($i['status'] ?? '') === 'pending_review'
    && !isset($idInCatalogo[(int) ($i['id'] ?? 0)]));
usort($pending, fn($a, $b) => strcmp($b['first_seen'] ?? '', $a['first_seen'] ?? ''));

// Gli atti in tema letti dalla Gazzetta Ufficiale hanno un archivio proprio -
// known.json e' indicizzato per IDPagina MASAF e appartiene a scraper.php - ma
// finiscono nello stesso riquadro: chi cura deve avere una coda sola da
// guardare, non due in pagine diverse.
$pendingGu = gazzetta_da_rivedere($gazzetta['items'] ?? []);
$totalePending = count($pending) + count($pendingGu);

$lastRun = $known['_meta']['last_run'] ?? null;
$lastRunLabel = $lastRun ? news_date_label($lastRun, 'd/m/Y H:i', 'data non leggibile') : 'mai eseguito';

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
        // Titoli e descrizioni degli allegati sono cercabili: "elenco unità",
        // "RecFishing" o il numero di un decreto di rettifica devono trovare la
        // voce che li porta, non solo il decreto principale.
        implode(' ', array_column($item['allegati'] ?? [], 'titolo')),
        implode(' ', array_column($item['allegati'] ?? [], 'desc')),
    ])));
}

$totalItems = 0;
$totalPdf = 0;
$years = [];
foreach ($sections as $section) {
    foreach ($section['items'] ?? [] as $item) {
        $totalItems++;
        // Conta i file scaricabili, non le voci che ne hanno almeno uno: una
        // scheda puo' portare il decreto piu' i suoi allegati.
        if (!empty($item['pdf'])) {
            $totalPdf++;
        }
        $totalPdf += count($item['allegati'] ?? []);
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

// Scheda MASAF dell'atto (ServeBLOB) se c'è, altrimenti il link esterno.
function decreto_link(array $item): string {
    return !empty($item['masaf_id'])
        ? 'https://www.masaf.gov.it/flex/cm/pages/ServeBLOB.php/L/IT/IDPagina/' . (int) $item['masaf_id']
        : ($item['external_url'] ?? '#');
}

// Striscia in alto: i decreti più recenti per data nel riferimento ("del 23/07/2026").
$recenti = [];
foreach ($sections as $section) {
    foreach ($section['items'] ?? [] as $item) {
        $ts = dinamica_data_da_testo((string) ($item['ref'] ?? ''));
        if ($ts !== null) {
            $recenti[] = ['ts' => $ts, 'src' => (string) ($section['title'] ?? ''),
                          'title' => (string) ($item['title'] ?? ''), 'url' => decreto_link($item)];
        }
    }
}

// Un atto appena rilevato e non ancora verificato è proprio quello che non deve
// passare inosservato: va nella striscia anche prima di entrare in catalogo.
function pending_recente(array $p): bool {
    $ts = strtotime((string) ($p['first_seen'] ?? ''));
    return $ts !== false && time() - $ts < 30 * 86400;
}
foreach ($pending as $p) {
    if (!pending_recente($p)) {
        continue;
    }
    $ts = dinamica_data_da_testo((string) ($p['ref'] ?? '')) ?? strtotime((string) $p['first_seen']);
    $recenti[] = ['ts' => $ts, 'src' => 'Nuovo · da verificare',
                  'title' => trim(($p['ref'] ?? '') . ' ' . $p['title']), 'url' => (string) $p['url']];
}
usort($recenti, static fn(array $a, array $b): int => $b['ts'] <=> $a['ts']);
$recenti = array_slice($recenti, 0, 15);

// Un decreto conta come nuovo per 60 giorni: escono molto più di rado delle notizie.
function decreto_nuovo(array $item): bool {
    $ts = dinamica_data_da_testo((string) ($item['ref'] ?? ''));
    return $ts !== null && time() - $ts < 60 * 86400;
}

function h(int|string|null $s): string {
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

// Testo descrittivo di un allegato, mostrato sopra il suo link di download.
// Il titolo viene premesso solo quando aggiunge informazione: su molti allegati
// la descrizione MASAF ripete gia' il numero di decreto del titolo ("D.D.
// n.350909 del 17/07/2026 Ripartizione della quota..."), e stamparlo due volte
// e' rumore. Su "Allegato 1", "Allegato 2" ecc. invece la numerazione serve a
// distinguere i file della stessa scheda, quindi resta.
function allegato_desc(array $a): string {
    $titolo = trim($a['titolo'] ?? '');
    $desc   = trim($a['desc'] ?? '');
    if ($desc === '') {
        return $titolo;
    }
    if ($titolo === '') {
        return $desc;
    }
    // Confronto sulle sole lettere e cifre: la punteggiatura del numero di
    // decreto non e' uniforme fra titolo e descrizione ("n. 0322105" / "n.0322105").
    $strip = fn(string $s): string => strtolower((string) preg_replace('/[^a-z0-9]/i', '', $s));
    return str_contains($strip($desc), $strip($titolo)) ? $desc : $titolo . ' — ' . $desc;
}

// Separa il numero di decreto ("D.D. n.359419 del 23/07/2026") in testa alla
// descrizione dal resto, cosi' il template puo' evidenziarlo come sulla scheda
// principale invece di lasciarlo annegato nel paragrafo.
function allegato_ref_split(string $testo): array {
    if (preg_match('/^(D\.?D\.?\s*n\.?\s*\d+\s*del\s*\d{2}\/\d{2}\/\d{4})\s*(.*)$/isu', $testo, $m)) {
        return ['ref' => $m[1], 'resto' => $m[2]];
    }
    return ['ref' => '', 'resto' => $testo];
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>Registro — Decreti pesca speciale e quote di cattura</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/temi/mare.css">
<link rel="stylesheet" href="assets/dinamica.css">
</head>
<body>
<div class="wrap">

  <div class="topbar"><a href="news.php">News dal mondo della pesca →</a><a href="bandi.php">Bandi per regione →</a><?php if (accesso_admin()): ?><a href="admin.php">Utenti</a><?php endif; ?><a href="logout.php">Esci</a></div>

  <div class="masthead">
    <p class="eyebrow">Registro normativo · pesca professionale · auto-aggiornato</p>
    <h1>Pesca speciale e quote di cattura — decreti in vigore</h1>
    <p class="lede">Raccolta di provvedimenti nazionali (MASAF) e regionali (Veneto) su quote di cattura, fermo pesca, pesca ricreativa e pesche speciali lagunari. Le nuove voci rilevate dallo scraper vengono segnalate per revisione manuale, non categorizzate automaticamente.</p>
    <div class="meta-row">
      <span>Ultimo controllo MASAF: <strong><?= h($lastRunLabel) ?></strong></span>
      <span>Voci catalogate: <strong data-conta="<?= $totalItems ?>"><?= $totalItems ?></strong></span>
      <span>Documenti PDF: <strong data-conta="<?= $totalPdf ?>"><?= $totalPdf ?></strong></span>
      <span>Da rivedere: <strong data-conta="<?= $totalePending ?>"><?= $totalePending ?></strong></span>
      <?php render_refresh_button('decreti'); ?>
    </div>
  </div>

  <?php render_refresh_banner('decreti'); ?>

  <?php dinamica_ticker($recenti, 'Ultimi decreti'); ?>

  <?php if ($archiviGuasti !== []): ?>
  <div class="avviso">
    ⚠ Questi archivi non sono leggibili e il loro contenuto non compare in pagina:
    <strong><?= h(implode(', ', $archiviGuasti)) ?></strong>. Quello che vedi è quindi incompleto,
    e la coda "da rivedere" può sembrare vuota senza esserlo. Ripristina i file da git
    (<code>git checkout -- data/</code>) oppure rilancia i programmi che li generano.
  </div>
  <?php endif; ?>

  <nav class="index" aria-label="Indice categorie">
    <?php foreach ($sections as $s): ?>
    <a class="chip" style="--chip-c: <?= h($s['color']) ?>" href="#<?= h($s['id']) ?>"><span class="dot"></span><?= h($s['title']) ?></a>
    <?php endforeach; ?>
    <?php if ($totalePending > 0): ?>
    <a class="chip" style="--chip-c: 176 108 46" href="#da-categorizzare"><span class="dot"></span>Da categorizzare</a>
    <?php endif; ?>
  </nav>

<?php render_searchbar('Cerca: numero di decreto, specie, parola chiave…', 'Cerca nel registro dei decreti'); ?>

  <div class="year-filter" role="group" aria-label="Filtra per anno">
    <span class="label">Filtra per anno</span>
    <button type="button" class="yr-chip" data-yr="all" aria-pressed="true">Tutti</button>
    <?php foreach ($years as $y): ?>
    <button type="button" class="yr-chip" data-yr="<?= h($y) ?>" aria-pressed="false"><?= $y === 'info' ? 'Info generali' : h($y) ?></button>
    <?php endforeach; ?>
  </div>

<?php render_no_results(); ?>

  <?php if ($totalePending > 0): ?>
  <details class="category pending-box" id="da-categorizzare" style="--cat-c: 176 108 46" open>
    <summary class="cat-head"><h2>⚠ Da categorizzare</h2><span class="count"><?= $totalePending ?> atti</span></summary>
    <p class="cat-desc">Atti rilevati automaticamente e non ancora verificati né inseriti nel catalogo. Si controllano, poi si spostano nella categoria giusta oppure si escludono.</p>
    <ul>
      <?php foreach ($pending as $p): ?>
      <li data-search="<?= h(search_blob(['ref' => $p['ref'] ?? '', 'title' => $p['title'], 'desc' => $p['titolo_completo'] ?? ''], 'da rivedere')) ?>">
        <?php if (!empty($p['ref'])): ?><span class="ref"><?= h($p['ref']) ?></span><?php endif; ?>
        <?php if (pending_recente($p)): ?><span class="nuovo">Nuovo · da verificare</span><?php endif; ?>
        <a href="<?= h(news_safe_url($p['url']) ? $p['url'] : '#') ?>" target="_blank" rel="noopener"><?= h($p['title']) ?></a>
        <div class="tag">rilevato il <?= h($p['first_seen']) ?> · IDPagina <?= (int) $p['id'] ?> · non ancora verificato/categorizzato</div>
      </li>
      <?php endforeach; ?>
      <?php foreach ($pendingGu as $g): ?>
      <li data-origine="gazzetta" data-search="<?= h(trim($g['titolo'] . ' ' . $g['oggetto'] . ' da rivedere gazzetta ufficiale')) ?>">
        <a href="<?= h(news_safe_url($g['url']) ? $g['url'] : '#') ?>" target="_blank" rel="noopener"><?= h($g['oggetto']) ?></a>
        <div class="tag">Gazzetta Ufficiale n. <?= (int) $g['numero_gu'] ?> del <?= h($g['data_gu']) ?> · <?= h($g['tipo_atto']) ?> · non ancora verificato/categorizzato</div>
      </li>
      <?php endforeach; ?>
    </ul>
  </details>
  <?php endif; ?>

  <?php foreach ($sections as $s): ?>
  <details class="category" id="<?= h($s['id']) ?>" style="--cat-c: <?= h($s['color']) ?>">
    <summary class="cat-head"><h2><?= h($s['title']) ?></h2><span class="count"><?= count($s['items'] ?? []) ?> voci</span></summary>
    <p class="cat-desc"><?= h($s['desc']) ?></p>
    <div class="cards">
      <?php foreach ($s['items'] ?? [] as $item): ?>
        <?php $linkUrl = decreto_link($item); ?>
      <?php /* Contenitore, non link: dentro un <a> non se ne puo' annidare un
               altro, e qui le destinazioni sono due e diverse — il titolo porta
               alla scheda MASAF (ServeBLOB), il bottone al PDF (ServeAttachment).
               'size' e' un campo a doppio uso: peso del file sulle voci con PDF,
               dominio di provenienza sulle altre. Da qui le due rese distinte. */ ?>
      <article class="card rivela solleva" data-year="<?= h(item_year($item)) ?>" data-search="<?= h(search_blob($item, $s['title'] ?? '')) ?>">
        <span class="ref"><?= h($item['ref']) ?><?php if (decreto_nuovo($item)): ?> <span class="nuovo">Nuovo</span><?php endif; ?></span>
        <h3><a href="<?= h($linkUrl) ?>" target="_blank" rel="noopener"><?= h($item['title']) ?></a></h3>
        <p><?= h($item['desc']) ?></p>
        <div class="foot">
          <div class="foot-main">
            <?php if (!empty($item['pdf'])): ?>
            <a class="dl" href="<?= h($item['pdf']) ?>">↓ Scarica PDF</a>
            <span class="size"><?= h($item['size'] ?? '') ?></span>
            <?php else: ?>
            <span class="source"><?= h($item['size'] ?? '') ?></span>
            <?php endif; ?>
          </div>
          <?php if (!empty($item['allegati'])): ?>
          <?php /* Elenchi di unità, note e manuali pubblicati sulla stessa
                   pagina MASAF del decreto: appartengono a questa scheda, non
                   sono atti a sé. check_allegati.php segnala quando ne compaiono
                   di nuovi. */ ?>
          <ul class="allegati">
            <?php foreach ($item['allegati'] as $a):
              $parts = allegato_ref_split(allegato_desc($a));
            ?>
            <li>
              <?php if ($parts['ref'] !== ''): ?>
              <span class="all-ref"><?= h($parts['ref']) ?></span>
              <?php endif; ?>
              <span class="all-desc"><?= h($parts['resto']) ?></span>
              <a class="dl" href="<?= h($a['pdf']) ?>">Scarica PDF</a>
              <span class="size"><?= h($a['size'] ?? '') ?></span>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </details>
  <?php endforeach; ?>

  <?php
  // Atti letti e scartati: restano visibili, chiusi, per tracciare la decisione presa.
  $esclusi = array_filter($known['items'] ?? [], fn($i) => ($i['status'] ?? '') === 'excluded');
  usort($esclusi, fn($a, $b) => strcmp($b['first_seen'] ?? '', $a['first_seen'] ?? ''));
  $esclusiGu = array_filter($gazzetta['items'] ?? [], fn($i) => ($i['status'] ?? '') === 'excluded');
  usort($esclusiGu, fn($a, $b) => strcmp($b['data_gu'] ?? '', $a['data_gu'] ?? ''));
  $totaleEsclusi = count($esclusi) + count($esclusiGu);
  ?>
  <?php if ($totaleEsclusi > 0): ?>
  <details class="category" id="esclusi" style="--cat-c: 107 100 89">
    <summary class="cat-head"><h2>Esclusi</h2><span class="count"><?= $totaleEsclusi ?> atti</span></summary>
    <p class="cat-desc">Atti letti e giudicati fuori dal registro: non riguardano quote né gestione della pesca, oppure sono bandi, fondi FEAMPA o atti di altra natura. Restano qui per tracciare la decisione.</p>
    <ul>
      <?php foreach ($esclusi as $e): ?>
      <li>
        <a href="<?= h(news_safe_url($e['url']) ? $e['url'] : '#') ?>" target="_blank" rel="noopener"><?= h($e['title']) ?></a>
        <div class="tag">rilevato il <?= h($e['first_seen'] ?? '') ?> · IDPagina <?= (int) $e['id'] ?> · escluso</div>
      </li>
      <?php endforeach; ?>
      <?php foreach ($esclusiGu as $g): ?>
      <li>
        <a href="<?= h(news_safe_url($g['url']) ? $g['url'] : '#') ?>" target="_blank" rel="noopener"><?= h($g['oggetto']) ?></a>
        <div class="tag">Gazzetta Ufficiale n. <?= (int) $g['numero_gu'] ?> del <?= h($g['data_gu']) ?> · escluso</div>
      </li>
      <?php endforeach; ?>
    </ul>
  </details>
  <?php endif; ?>

  <footer class="note">
    Pagina generata dinamicamente da <code>data/catalog.json</code> (curato e verificato a mano) e <code>data/known.json</code>
    (aggiornato da <code>scraper.php</code>, eseguito periodicamente via Task Scheduler). Ultimo controllo dell'indice MASAF:
    <strong><?= h($lastRunLabel) ?></strong>. Le voci "da rivedere" sono rilevate automaticamente ma non categorizzate:
    richiedono verifica manuale del testo del decreto prima di essere aggiunte al catalogo.
    Gli atti pubblicati in Gazzetta Ufficiale sono raccolti da <code>gazzetta_fetcher.php</code>, che legge il sommario
    dell'ultimo fascicolo di ogni serie seguita e ne tiene le voci in tema. Il feed non è un archivio: quando
    un'esecuzione salta dei fascicoli, i numeri non visti restano registrati in <code>data/gazzetta.json</code> e nel log.
  </footer>

</div>

<script src="assets/filters.js"></script>
<script>
initFilters({
  chipAttr: "data-yr",
  itemAttr: "data-year",
  containers: ["details.category"],
  labels: { one: "voce trovata", many: "voci trovate" }
});
// Le categorie si aprono col clic sul titolo. Un link dall'indice in alto apre
// quella di destinazione; durante una ricerca si aprono tutte, così i risultati si vedono.
(function () {
  function apriDa(hash) {
    var el = hash && document.getElementById(hash.slice(1));
    if (el && el.tagName === "DETAILS") el.open = true;
  }
  apriDa(location.hash);
  window.addEventListener("hashchange", function () { apriDa(location.hash); });
  var q = document.getElementById("q");
  if (q) q.addEventListener("input", function () {
    if (q.value.trim() === "") return;
    document.querySelectorAll("details.category").forEach(function (d) { d.open = true; });
  });
})();
</script>
<script src="assets/dinamica.js"></script>
</body>
</html>
