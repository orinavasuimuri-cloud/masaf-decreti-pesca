<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bandi_normalize.php';
require_once __DIR__ . '/lib/bandi_store.php';

$dataDir = __DIR__ . '/data';
$store   = bandi_store_load($dataDir . '/bandi.json');
$regCfg  = json_decode((string) @file_get_contents($dataDir . '/bandi_regioni.json'), true) ?? ['regioni' => []];
$fonti   = json_decode((string) @file_get_contents($dataDir . '/bandi_fonti.json'), true) ?? ['feed' => []];

$now  = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('c');
$oggi = substr($now, 0, 10);

function h(int|string|null $s): string {
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function data_it(?string $iso): string {
    return $iso === null || $iso === '' ? '—' : date('d/m/Y', strtotime($iso));
}

// Lo stato non è salvato nel JSON: si calcola qui, perché dipende da oggi.
// Un bando aperto diventa chiuso al passare della scadenza anche senza fetch.
$items = $store['items'];
foreach ($items as $i => $v) {
    $items[$i]['stato'] = bandi_stato($v['scadenza'], $v['terminato_in_fonte'], $oggi);
}

$coloriStato = [
    'aperto' => '62 115 104',
    'chiuso' => '107 100 89',
    'da_verificare' => '156 122 46',
];

// Indice per regione: una voce con più regioni compare in ognuna.
$perRegione = [];
foreach ($items as $v) {
    foreach ($v['regioni'] as $slug) {
        $perRegione[$slug][] = $v;
    }
    if ($v['regioni'] === []) {
        $perRegione['_non_attribuiti'][] = $v;
    }
}

$aperti = array_values(array_filter($items, static fn(array $v): bool => $v['stato'] === 'aperto'));
usort($aperti, static fn(array $a, array $b): int => strcmp((string) $a['scadenza'], (string) $b['scadenza']));

$nomiRegione = [];
foreach ($regCfg['regioni'] as $r) {
    $nomiRegione[$r['slug']] = $r['nome'];
}

// Sezioni: prima i bandi nazionali, poi le regioni con almeno una voce, infine i non attribuiti.
$sezioni = [];
if (!empty($perRegione['bandi-masaf-nazionali'])) {
    $sezioni[] = ['slug' => 'bandi-masaf-nazionali', 'nome' => 'Bandi MASAF nazionali',
                  'cfg' => null, 'voci' => $perRegione['bandi-masaf-nazionali']];
}
foreach ($regCfg['regioni'] as $r) {
    $voci = $perRegione[$r['slug']] ?? [];
    if ($voci === []) {
        continue;
    }
    $sezioni[] = ['slug' => $r['slug'], 'nome' => $r['nome'], 'cfg' => $r, 'voci' => $voci];
}
if (!empty($perRegione['_non_attribuiti'])) {
    $sezioni[] = ['slug' => '_non_attribuiti', 'nome' => 'Non attribuiti',
                  'cfg' => null, 'voci' => $perRegione['_non_attribuiti']];
}
foreach ($sezioni as $i => $s) {
    usort($sezioni[$i]['voci'], static fn(array $a, array $b): int
        => strcmp((string) $b['pubblicazione'], (string) $a['pubblicazione']));
}

// Salute: si controllano l'aggregatore (una voce per regione) e i feed dichiarati.
// Gli id interni ("regione-toscana", "basilicata-rss") non sono leggibili in
// pagina: si traducono nel nome della regione o nell'etichetta del feed.
$etichette = [];
foreach ($nomiRegione as $slug => $nome) {
    $etichette["regione-$slug"] = $nome;
}
$etichette['regione-bandi-masaf-nazionali'] = 'Bandi MASAF nazionali';
foreach ($fonti['feed'] ?? [] as $f) {
    $etichette[$f['id']] = $f['label'];
}

$ferme = [];
foreach (array_keys($store['_meta']['fonti']) as $id) {
    if (bandi_fonte_is_stale($store, (string) $id, $now)) {
        $ferme[] = $etichette[$id] ?? (string) $id;
    }
}
$copertura = $store['_meta']['copertura'] ?? ['attesi_api' => 0, 'raccolti' => 0];
$daAggregatore = count(array_filter($items, static fn(array $v): bool => $v['origine'] === 'aggregatore'));
$scarto = ((int) $copertura['attesi_api']) - $daAggregatore;

$lastRun = $store['_meta']['last_run'] ?? null;
$lastRunLabel = $lastRun ? date('d/m/Y H:i', strtotime($lastRun)) : 'mai eseguito';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>Bandi pesca per regione</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <a href="index.php">← Registro decreti</a>
    <a href="news.php">News dal mondo della pesca</a>
  </div>

  <div class="masthead">
    <p class="eyebrow">Finanziamenti · FEAMPA 2021-2027 · aggiornamento automatico</p>
    <h1>Bandi per la pesca, regione per regione</h1>
    <p class="lede">Avvisi e bandi che riguardano pesca e acquacoltura, raccolti automaticamente e
      ordinati per territorio. I bandi in scadenza sono in evidenza; l'archivio dei bandi chiusi
      resta consultabile per intero.</p>
    <div class="meta-row">
      <span>Ultimo aggiornamento: <strong><?= h($lastRunLabel) ?></strong></span>
      <span>Bandi: <strong><?= count($items) ?></strong></span>
      <span>Aperti: <strong><?= count($aperti) ?></strong></span>
      <span>Regioni con bandi: <strong><?= count($sezioni) ?></strong></span>
    </div>
  </div>

  <div class="bandi-disclaimer">
    La fonte principale di questa pagina è <strong>FEAMPA Bandi Online</strong>, un aggregatore
    <strong>privato</strong> (Consorzio Mediterraneo con Legacoop Agroalimentare), non un canale
    istituzionale: in caso di divergenza fa fede il sito della Regione, raggiungibile dal link in
    testa a ogni sezione. Le voci contrassegnate <em>segnalazione</em> arrivano dai canali
    istituzionali regionali e non hanno una scadenza verificata.
  </div>

  <?php if ($ferme): ?>
  <div class="bandi-avviso">
    ⚠ Nessun aggiornamento da oltre 7 giorni per: <strong><?= h(implode(', ', $ferme)) ?></strong>.
    I bandi già raccolti restano consultabili.
  </div>
  <?php endif; ?>

  <?php if ($scarto > 0): ?>
  <div class="bandi-avviso">
    ⚠ Copertura incompleta: la fonte dichiara <strong><?= h((string) $copertura['attesi_api']) ?></strong>
    bandi, ne sono stati raccolti <strong><?= h((string) $daAggregatore) ?></strong>.
    Verificare sui siti regionali.
  </div>
  <?php endif; ?>

  <?php if (!$items): ?>
  <div class="bandi-empty">
    Nessun bando ancora raccolto. Esegui <code>php bandi_fetcher.php</code> per popolare la pagina.
  </div>
  <?php else: ?>

  <?php if ($aperti): ?>
  <div class="bandi-aperti">
    <h2>In scadenza · <?= count($aperti) ?> bandi aperti</h2>
    <?php foreach ($aperti as $v): ?>
    <div class="bandi-row">
      <span class="scad">scade <?= h(data_it($v['scadenza'])) ?></span>
      <span class="reg"><?= h(implode(', ', array_map(
          static fn(string $s): string => $nomiRegione[$s] ?? $s, $v['regioni']))) ?></span>
      <a href="<?= h($v['url_fonte']) ?>" target="_blank" rel="noopener"><?= h($v['titolo']) ?></a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="year-filter" role="group" aria-label="Filtra per stato">
    <span class="label">Mostra</span>
    <button type="button" class="yr-chip" data-stato="all" aria-pressed="true">Tutti</button>
    <button type="button" class="yr-chip" data-stato="aperto" aria-pressed="false">Solo aperti</button>
  </div>

  <div class="index">
    <?php foreach ($sezioni as $s): ?>
    <a class="chip" href="#sez-<?= h($s['slug']) ?>"><?= h($s['nome']) ?> <?= count($s['voci']) ?></a>
    <?php endforeach; ?>
  </div>

  <?php foreach ($sezioni as $s): ?>
  <section class="bandi-sec" id="sez-<?= h($s['slug']) ?>">
    <div class="bandi-sec-head">
      <h2><?= h($s['nome']) ?></h2>
      <span class="count"><?= count($s['voci']) ?> bandi</span>
    </div>

    <?php if ($s['cfg'] !== null): ?>
    <div class="bandi-links">
      <?php if (!empty($s['cfg']['calendario_ufficiale'])): ?>
      <span><span class="lbl">Calendario ufficiale:</span>
        <a href="<?= h($s['cfg']['calendario_ufficiale']) ?>" target="_blank" rel="noopener">sito della Regione ↗</a></span>
      <?php endif; ?>
      <?php if (!empty($s['cfg']['flag'])): ?>
      <span><span class="lbl">FLAG del territorio:</span>
        <?php foreach ($s['cfg']['flag'] as $k => $f): ?><?= $k > 0 ? ' · ' : '' ?><a href="<?= h($f['url']) ?>" target="_blank" rel="noopener"><?= h($f['nome']) ?></a><?php endforeach; ?>
      </span>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php foreach ($s['voci'] as $v): ?>
    <article class="bandi-card" data-stato="<?= h($v['stato']) ?>"
             style="--st-c: <?= h($coloriStato[$v['stato']]) ?>">
      <div class="head">
        <span class="bandi-badge"><?= h($v['stato'] === 'da_verificare' ? 'segnalazione' : $v['stato']) ?></span>
        <?php if ($v['codice_intervento'] !== ''): ?>
        <span class="cod">cod. <?= h($v['codice_intervento']) ?></span>
        <?php endif; ?>
        <span class="date">
          <?php if ($v['scadenza'] !== null): ?>scade <?= h(data_it($v['scadenza'])) ?>
          <?php else: ?>pubbl. <?= h(data_it($v['pubblicazione'])) ?><?php endif; ?>
        </span>
      </div>
      <h3><a href="<?= h($v['url_fonte']) ?>" target="_blank" rel="noopener"><?= h($v['titolo']) ?></a></h3>
      <?php if ($v['priorita'] !== '' && $v['priorita'] !== $v['titolo']): ?>
      <p class="prio"><?= h($v['priorita']) ?></p>
      <?php endif; ?>
      <?php if ($v['scopo'] !== '' && $v['scopo'] !== $v['titolo']): ?>
      <p><?= h($v['scopo']) ?></p>
      <?php endif; ?>
      <?php if ($v['nota'] !== ''): ?>
      <p class="nota"><?= h($v['nota']) ?></p>
      <?php endif; ?>
      <div class="foot">
        <?php if ($v['url_ufficiale'] !== null): ?>
        <a href="<?= h($v['url_ufficiale']) ?>" target="_blank" rel="noopener">Atto ufficiale ↗</a>
        <?php endif; ?>
        <?php if ($v['origine'] === 'istituzionale'): ?>
        <span class="prio">segnalazione dal canale istituzionale regionale</span>
        <?php endif; ?>
        <?php if ($v['dettagli_mancanti'] && $v['scopo'] === ''): ?>
        <span class="incompleto">La fonte non pubblica una descrizione per questo bando: consultare la scheda o l'atto ufficiale.</span>
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </section>
  <?php endforeach; ?>

  <?php endif; ?>

  <footer class="note">
    Pagina generata da <code>data/bandi.json</code>, aggiornato da <code>bandi_fetcher.php</code>
    una volta al giorno via Task Scheduler. L'archivio dei bandi chiusi non viene mai potato.
    Titoli e descrizioni appartengono alle fonti indicate: per il testo integrale seguire i
    collegamenti.
  </footer>

</div>

<script>
(function () {
  var buttons = document.querySelectorAll(".yr-chip[data-stato]");
  var cards = document.querySelectorAll(".bandi-card");
  buttons.forEach(function (btn) {
    btn.addEventListener("click", function () {
      buttons.forEach(function (b) { b.setAttribute("aria-pressed", "false"); });
      btn.setAttribute("aria-pressed", "true");
      var stato = btn.getAttribute("data-stato");
      cards.forEach(function (el) {
        el.style.display = (stato === "all" || el.getAttribute("data-stato") === stato) ? "" : "none";
      });
      document.querySelectorAll(".bandi-sec").forEach(function (sec) {
        var visibili = sec.querySelectorAll('.bandi-card:not([style*="display: none"])').length;
        sec.style.display = visibili === 0 ? "none" : "";
      });
    });
  });
})();
</script>
</body>
</html>
