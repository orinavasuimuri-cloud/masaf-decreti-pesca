<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/searchbar.php';

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
      <?php /* Contenitore, non link: dentro un <a> non se ne puo' annidare un
               altro, e qui le destinazioni sono due e diverse — il titolo porta
               alla scheda MASAF (ServeBLOB), il bottone al PDF (ServeAttachment).
               'size' e' un campo a doppio uso: peso del file sulle voci con PDF,
               dominio di provenienza sulle altre. Da qui le due rese distinte. */ ?>
      <article class="card" data-year="<?= h(item_year($item)) ?>" data-search="<?= h(search_blob($item, $s['title'] ?? '')) ?>">
        <span class="ref"><?= h($item['ref']) ?></span>
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
            <?php foreach ($item['allegati'] as $a): ?>
            <li>
              <span class="all-desc"><?= h(allegato_desc($a)) ?></span>
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
  </section>
  <?php endforeach; ?>

  <footer class="note">
    Pagina generata dinamicamente da <code>data/catalog.json</code> (curato e verificato a mano) e <code>data/known.json</code>
    (aggiornato da <code>scraper.php</code>, eseguito periodicamente via Task Scheduler). Ultimo controllo dell'indice MASAF:
    <strong><?= h($lastRunLabel) ?></strong>. Le voci "da rivedere" sono rilevate automaticamente ma non categorizzate:
    richiedono verifica manuale del testo del decreto prima di essere aggiunte al catalogo.
  </footer>

</div>

<script src="assets/filters.js"></script>
<script>
initFilters({
  chipAttr: "data-yr",
  itemAttr: "data-year",
  containers: ["section.category", ".pending-box"],
  labels: { one: "voce trovata", many: "voci trovate" }
});
</script>
</body>
</html>
