<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/accesso.php';
accesso_richiedi();

require_once __DIR__ . '/lib/news_normalize.php';
require_once __DIR__ . '/lib/bandi_normalize.php';
require_once __DIR__ . '/lib/bandi_store.php';
require_once __DIR__ . '/lib/searchbar.php';
require_once __DIR__ . '/lib/refresh_ui.php';
require_once __DIR__ . '/lib/dinamica.php';

// date.timezone è UTC sul server: senza questo l'ora mostrata in "ultimo
// aggiornamento" sarebbe sfasata di due ore rispetto a quella reale.
date_default_timezone_set('Europe/Rome');

$dataDir = __DIR__ . '/data';

// bandi_store_load() lancia RuntimeException quando il file è presente ma
// illeggibile o non decodificabile (lib/bandi_store.php): serve a impedire
// che il fetcher sovrascriva l'archivio partendo da uno store vuoto. In
// pagina la stessa eccezione non deve mai diventare un errore fatale: un
// guasto dei dati può rompere il fetcher, mai il sito. Si degrada quindi a
// uno store vuoto e si mostra un avviso, distinto dal caso "file assente"
// (che bandi_store_load() già gestisce restituendo lo store vuoto senza
// eccezione, ed è coperto più sotto dal riquadro "Nessun bando ancora
// raccolto").
$storeErrore = null;
try {
    $store = bandi_store_load($dataDir . '/bandi.json');
} catch (RuntimeException $e) {
    $store = bandi_store_empty();
    $storeErrore = $e->getMessage();
}
$regCfg  = json_decode((string) @file_get_contents($dataDir . '/bandi_regioni.json'), true) ?? ['regioni' => []];
$fonti   = json_decode((string) @file_get_contents($dataDir . '/bandi_fonti.json'), true) ?? ['feed' => [], 'flag' => []];

$now  = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('c');
$oggi = substr($now, 0, 10);

function h(int|string|null $s): string {
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * data('d/m/Y', strtotime($iso)) sotto strict_types=1 manda in bianco l'intera
 * pagina non appena una data è corrotta: strtotime() fallito restituisce
 * false, e date() rifiuta un timestamp che non è un int con un TypeError
 * fatale. news_date_label() (lib/news_normalize.php, già in uso in news.php)
 * neutralizza esattamente questo caso.
 */
function data_it(?string $iso): string {
    return news_date_label($iso, 'd/m/Y', '—');
}

// Lo stato non è salvato nel JSON: si calcola qui, perché dipende da oggi.
// Un bando aperto diventa chiuso al passare della scadenza anche senza fetch.
$items = $store['items'];
foreach ($items as $i => $v) {
    $items[$i]['stato'] = bandi_stato($v['scadenza'], $v['terminato_in_fonte'], $oggi);
    // I link arrivano dai feed di 42 siti esterni: uno compromesso potrebbe
    // mandare javascript:, che qui diventerebbe un link cliccabile.
    if (!news_safe_url($v['url_fonte'])) {
        $items[$i]['url_fonte'] = '#';
    }
    if (!news_safe_url($v['url_ufficiale'])) {
        $items[$i]['url_ufficiale'] = null;
    }
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
$nomiRegione['bandi-masaf-nazionali'] ??= 'Nazionale (MASAF)';

/** Giorni che mancano alla scadenza (0 = scade oggi), null se la data non è leggibile. */
function giorni_alla_scadenza(?string $scadenza, string $oggi): ?int
{
    if ($scadenza === null || $scadenza === '') {
        return null;
    }
    $s = DateTimeImmutable::createFromFormat('!Y-m-d', substr($scadenza, 0, 10));
    $o = DateTimeImmutable::createFromFormat('!Y-m-d', $oggi);
    if ($s === false || $o === false) {
        return null;
    }
    return (int) $o->diff($s)->format('%r%a');
}

/** Testo e livello di urgenza del conto alla rovescia di un bando aperto. */
function conto_alla_rovescia(?int $giorni): array
{
    return match (true) {
        $giorni === null => ['', ''],
        $giorni <= 0     => ['scade oggi', 'urgente'],
        $giorni === 1    => ['scade domani', 'urgente'],
        $giorni <= 7     => ["mancano $giorni giorni", 'urgente'],
        $giorni <= 30    => ["mancano $giorni giorni", 'vicino'],
        default          => ["mancano $giorni giorni", ''],
    };
}

$etichetteStato = ['aperto' => 'Aperto', 'chiuso' => 'Chiuso', 'da_verificare' => 'Segnalazione'];

/**
 * L'aggregatore non pubblica un titolo: il fetcher lo ricava troncando lo scopo
 * (bandi_titolo_da_scopo). In pagina quel troncamento ripeterebbe la stessa
 * frase due volte, tagliata e intera: si mostra lo scopo intero come titolo e
 * lo si lascia accorciare al CSS.
 */
function titolo_mostrato(array $v): string
{
    $titolo = (string) $v['titolo'];
    $scopo  = (string) $v['scopo'];
    if ($scopo !== '' && str_ends_with($titolo, '…') && str_starts_with($scopo, rtrim(substr($titolo, 0, -strlen('…'))))) {
        $titolo = $scopo;
    }
    return ucfirst($titolo);
}

$apertiEntro30 = count(array_filter($aperti, static function (array $v) use ($oggi): bool {
    $g = giorni_alla_scadenza($v['scadenza'], $oggi);
    return $g !== null && $g <= 30;
}));

$tickerBandi = array_map(static fn(array $v): array => [
    'src'   => implode(', ', array_map(static fn(string $s): string => $nomiRegione[$s] ?? $s, $v['regioni'])) ?: 'Bando',
    'title' => ucfirst((string) $v['titolo']) . ' · scade ' . news_date_label($v['scadenza'], 'd/m/Y', '—'),
    'url'   => $v['url_fonte'],
], $aperti);

/**
 * Testo indicizzato dalla ricerca: oltre a titolo e descrizioni comprende il
 * codice intervento (si cerca spesso per quello) e i nomi estesi delle regioni,
 * che in pagina compaiono solo nel titolo di sezione.
 */
function bandi_search_blob(array $v, array $nomiRegione): string
{
    $regioni = array_map(static fn(string $s): string => $nomiRegione[$s] ?? $s, $v['regioni']);
    return trim(implode(' ', array_filter([
        $v['titolo'] ?? '',
        $v['codice_intervento'] ?? '',
        $v['priorita'] ?? '',
        $v['scopo'] ?? '',
        $v['nota'] ?? '',
        implode(' ', $regioni),
        (string) ($v['fonte_label'] ?? ''),
    ])));
}

// Sezioni: prima i bandi nazionali, poi le regioni con almeno una voce, infine i non attribuiti.
$sezioni = [];
$slugConsumati = [];
if (!empty($perRegione['bandi-masaf-nazionali'])) {
    $sezioni[] = ['slug' => 'bandi-masaf-nazionali', 'nome' => 'Bandi MASAF nazionali',
                  'cfg' => null, 'voci' => $perRegione['bandi-masaf-nazionali']];
}
$slugConsumati['bandi-masaf-nazionali'] = true;
foreach ($regCfg['regioni'] as $r) {
    $slugConsumati[$r['slug']] = true;
    $voci = $perRegione[$r['slug']] ?? [];
    if ($voci === []) {
        continue;
    }
    $sezioni[] = ['slug' => $r['slug'], 'nome' => $r['nome'], 'cfg' => $r, 'voci' => $voci];
}
// Uno slug uscito dalle classi CSS dell'aggregatore ma non censito in
// bandi_regioni.json (una categoria territoriale nuova, mai vista finora) non
// va scartato in silenzio: finisce in "Non attribuiti" insieme alle voci
// senza alcuna categoria territoriale, invece di sparire dalla pagina pur
// restando contato nel totale e salvato nel JSON.
foreach ($perRegione as $slug => $voci) {
    if (isset($slugConsumati[$slug]) || $slug === '_non_attribuiti') {
        continue;
    }
    $perRegione['_non_attribuiti'] = array_merge($perRegione['_non_attribuiti'] ?? [], $voci);
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
foreach ($fonti['flag'] ?? [] as $f) {
    $etichette[$f['id']] = $f['label'];
}
// Anche la Gazzetta Ufficiale travasa voci qui (origine "gazzetta").
$fontiGu = json_decode((string) @file_get_contents($dataDir . '/gazzetta_fonti.json'), true) ?? [];
foreach ($fontiGu['serie'] ?? [] as $f) {
    $etichette[$f['id']] = $f['label'];
}
// Qui la Gazzetta risulta aggiornata solo quando travasa un bando, cosa rara:
// la sua salute la dice gazzetta.json, che segna ogni lettura riuscita.
$metaGu = json_decode((string) @file_get_contents($dataDir . '/gazzetta.json'), true)['_meta']['serie'] ?? [];
foreach ($metaGu as $id => $m) {
    if (isset($store['_meta']['fonti'][$id]) && !empty($m['last_ok'])) {
        $store['_meta']['fonti'][$id]['last_ok'] = $m['last_ok'];
    }
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

// "mai eseguito" (fetcher mai girato) e "data non leggibile" (_meta.last_run
// presente ma corrotto) sono fatti diversi: nessuno dei due deve far cadere
// la pagina (stesso pattern di news.php).
$lastRun = $store['_meta']['last_run'] ?? null;
$lastRunLabel = ($lastRun === null || $lastRun === '')
    ? 'mai eseguito'
    : news_date_label($lastRun, 'd/m/Y H:i', 'data non leggibile');
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>Bandi pesca per regione</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/temi/mare.css">
<link rel="stylesheet" href="assets/dinamica.css">
<link rel="stylesheet" href="assets/bandi.css">
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <a href="index.php">← Registro decreti</a>
    <a href="news.php">News dal mondo della pesca</a>
    <?php if (accesso_admin()): ?><a href="admin.php">Utenti</a><?php endif; ?><a href="logout.php">Esci</a>
  </div>

  <div class="masthead">
    <p class="eyebrow">Finanziamenti · FEAMPA 2021-2027 · aggiornamento automatico</p>
    <h1>Bandi per la pesca, regione per regione</h1>
    <p class="lede">Avvisi e bandi che riguardano pesca e acquacoltura, raccolti automaticamente e
      ordinati per territorio. I bandi in scadenza sono in evidenza; l'archivio dei bandi chiusi
      resta consultabile per intero.</p>
    <div class="kpi">
      <div class="kpi-box kpi-primo"><span class="kpi-num" data-conta="<?= count($aperti) ?>"><?= count($aperti) ?></span><span class="kpi-lbl">bandi aperti</span></div>
      <div class="kpi-box"><span class="kpi-num" data-conta="<?= $apertiEntro30 ?>"><?= $apertiEntro30 ?></span><span class="kpi-lbl">scadono entro 30 giorni</span></div>
      <div class="kpi-box"><span class="kpi-num" data-conta="<?= count($items) ?>"><?= count($items) ?></span><span class="kpi-lbl">bandi e segnalazioni in archivio</span></div>
      <div class="kpi-box"><span class="kpi-num" data-conta="<?= count($sezioni) ?>"><?= count($sezioni) ?></span><span class="kpi-lbl">territori con bandi</span></div>
    </div>
    <div class="meta-row">
      <span>Ultimo aggiornamento: <strong><?= h($lastRunLabel) ?></strong></span>
      <span>Segnalazioni FLAG: <strong><?= count(array_filter($items, static fn(array $v): bool => $v['origine'] === 'flag')) ?></strong></span>
      <?php render_refresh_button('bandi'); ?>
    </div>
  </div>

  <?php render_refresh_banner('bandi'); ?>

  <?php dinamica_ticker($tickerBandi, 'Bandi aperti'); ?>

  <details class="bandi-fonti">
    <summary>Da dove arrivano i dati e quanto sono affidabili</summary>
  <div class="bandi-disclaimer">
    La fonte principale di questa pagina è <strong>FEAMPA Bandi Online</strong>, un aggregatore
    <strong>privato</strong> (Consorzio Mediterraneo con Legacoop Agroalimentare), non un canale
    istituzionale: in caso di divergenza fa fede il sito della Regione, raggiungibile dal link in
    testa a ogni sezione. Il <strong>Bollettino Ufficiale</strong> della Regione, dove ne esiste un
    indirizzo stabile, è collegato in testa alla sezione: è la pubblicazione legale dell'avviso,
    ma non espone un elenco leggibile automaticamente, quindi le sue voci non compaiono qui. Le voci contrassegnate
    <em>segnalazione</em> arrivano dai canali
    istituzionali regionali o dai FLAG (Gruppi di Azione Locale della pesca) del territorio:
    non hanno una scadenza verificata, e dove la fonte si può nominare — un FLAG, oppure
    l'archivio bandi di una Regione — è indicata accanto alla voce. Quasi tutte pubblicano un
    feed; fa eccezione la <strong>Campania</strong>, il cui archivio FEAMPA è una tabella letta
    direttamente, ed è l'unica fonte regionale da cui arrivano anche gli estremi del decreto. I FLAG senza feed restano linkati in testa a ogni sezione, senza le
    loro voci in elenco.
  </div>
  </details>

  <?php if ($storeErrore !== null): ?>
  <div class="avviso">
    ⚠ L'archivio dei bandi (<code>data/bandi.json</code>) non è leggibile e non può essere mostrato:
    il file risulta corrotto o incompleto. Rilancia <code>php bandi_fetcher.php</code> dopo aver
    verificato l'archivio, oppure ripristina il file da git (<code>git checkout -- data/bandi.json</code>).
  </div>
  <?php endif; ?>

  <?php if ($ferme): ?>
  <div class="avviso">
    ⚠ Nessun aggiornamento da oltre 7 giorni per: <strong><?= h(implode(', ', $ferme)) ?></strong>.
    I bandi già raccolti restano consultabili.
  </div>
  <?php endif; ?>

  <?php if ($scarto > 0): ?>
  <div class="avviso">
    ⚠ Copertura incompleta: la fonte dichiara <strong><?= h((string) $copertura['attesi_api']) ?></strong>
    bandi, ne sono stati raccolti <strong><?= h((string) $daAggregatore) ?></strong>.
    Verificare sui siti regionali.
  </div>
  <?php endif; ?>

  <?php if (!$items): ?>
  <div class="bandi-empty">
    <?php if ($storeErrore !== null): ?>
    L'archivio non è leggibile: nessun bando può essere mostrato finché il file non viene
    riparato o rieseguito (vedi l'avviso sopra).
    <?php else: ?>
    Nessun bando ancora raccolto. Esegui <code>php bandi_fetcher.php</code> per popolare la pagina.
    <?php endif; ?>
  </div>
  <?php else: ?>

<?php render_searchbar('Cerca nei bandi: regione, codice intervento, parola chiave…', 'Cerca nei bandi'); ?>

  <?php if ($aperti): ?>
  <section class="bandi-aperti">
    <div class="bandi-aperti-head">
      <h2>Bandi aperti</h2>
      <span class="count"><?= count($aperti) ?> in ordine di scadenza</span>
    </div>
    <div class="tabella-scroll">
    <table class="tabella-aperti">
      <thead><tr><th scope="col">Scadenza</th><th scope="col">Territorio</th><th scope="col">Bando</th></tr></thead>
      <tbody>
      <?php foreach ($aperti as $v):
          [$contoTesto, $contoLivello] = conto_alla_rovescia(giorni_alla_scadenza($v['scadenza'], $oggi)); ?>
      <?php /* data-nocount: queste righe ripetono bandi già contati più sotto.
               data-stato è sempre "aperto" per costruzione ($aperti), ma va scritto
               lo stesso: senza, un eventuale chip diverso da "aperto" le lascerebbe
               in pagina. */ ?>
      <tr class="bandi-row" data-nocount data-stato="<?= h($v['stato']) ?>"
          data-search="<?= h(bandi_search_blob($v, $nomiRegione)) ?>">
        <td class="scad">
          <strong><?= h(data_it($v['scadenza'])) ?></strong>
          <?php if ($contoTesto !== ''): ?><span class="conto <?= h($contoLivello) ?>"><?= h($contoTesto) ?></span><?php endif; ?>
        </td>
        <td class="reg"><?= h(implode(', ', array_map(
            static fn(string $s): string => $nomiRegione[$s] ?? $s, $v['regioni']))) ?></td>
        <td class="tit">
          <a href="<?= h($v['url_fonte']) ?>" target="_blank" rel="noopener"><?= h(titolo_mostrato($v)) ?></a>
          <?php if ($v['codice_intervento'] !== ''): ?><span class="cod">cod. <?= h($v['codice_intervento']) ?></span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>
  <?php endif; ?>

  <div class="year-filter" role="group" aria-label="Filtra per stato">
    <span class="label">Mostra</span>
    <button type="button" class="yr-chip" data-stato="all" aria-pressed="true">Tutti</button>
    <button type="button" class="yr-chip" data-stato="aperto" aria-pressed="false">Solo aperti</button>
  </div>

<?php render_no_results('Nessun bando corrisponde ai filtri attivi.'); ?>

  <?php
  foreach ($sezioni as $i => $s) {
      $sezioni[$i]['aperti'] = count(array_filter($s['voci'], static fn(array $v): bool => $v['stato'] === 'aperto'));
  }
  ?>
  <nav class="index" aria-label="Territori">
    <?php foreach ($sezioni as $s): ?>
    <a class="chip<?= $s['aperti'] > 0 ? ' con-aperti' : '' ?>" href="#sez-<?= h($s['slug']) ?>"><?= h($s['nome']) ?>
      <span class="chip-n"><?= count($s['voci']) ?></span><?php if ($s['aperti'] > 0): ?><span class="chip-aperti" title="bandi aperti"><?= $s['aperti'] ?> aperti</span><?php endif; ?></a>
    <?php endforeach; ?>
  </nav>

  <?php foreach ($sezioni as $s): ?>
  <details class="bandi-sec" id="sez-<?= h($s['slug']) ?>">
    <summary class="bandi-sec-head">
      <h2><?= h($s['nome']) ?></h2>
      <?php if ($s['aperti'] > 0): ?><span class="sec-aperti"><?= $s['aperti'] ?> aperti</span><?php endif; ?>
      <span class="count"><?= count($s['voci']) ?> voci</span>
    </summary>

    <?php if ($s['cfg'] !== null): ?>
    <div class="bandi-links">
      <?php if (!empty($s['cfg']['calendario_ufficiale'])): ?>
      <span><span class="lbl">Calendario ufficiale:</span>
        <a href="<?= h($s['cfg']['calendario_ufficiale']) ?>" target="_blank" rel="noopener">sito della Regione ↗</a></span>
      <?php endif; ?>
      <?php if (!empty($s['cfg']['bur'])): ?>
      <?php /* Il Bollettino Ufficiale e' la pubblicazione legale dell'avviso:
               quando l'aggregatore e la Regione divergono, e' qui che si
               verifica chi ha ragione. Non se ne leggono le voci - nessun BUR
               espone un feed - quindi resta un collegamento, come i FLAG che
               non ne hanno uno. */ ?>
      <span class="bur-link">· <a class="bur" href="<?= h($s['cfg']['bur']) ?>" target="_blank" rel="noopener">Bollettino Ufficiale ↗</a></span>
      <?php endif; ?>
      <?php if (!empty($s['cfg']['flag'])): ?>
      <span><span class="lbl">FLAG del territorio:</span>
        <?php foreach ($s['cfg']['flag'] as $k => $f): ?><?= $k > 0 ? ' · ' : '' ?><a href="<?= h($f['url']) ?>" target="_blank" rel="noopener"><?= h($f['nome']) ?></a><?php endforeach; ?>
      </span>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="bandi-grid">
    <?php foreach ($s['voci'] as $v):
        $conto = $v['stato'] === 'aperto' ? conto_alla_rovescia(giorni_alla_scadenza($v['scadenza'], $oggi)) : ['', '']; ?>
    <article class="bandi-card rivela solleva" data-stato="<?= h($v['stato']) ?>"
             data-search="<?= h(bandi_search_blob($v, $nomiRegione)) ?>"
             style="--st-c: <?= h($coloriStato[$v['stato']]) ?>">
      <div class="head">
        <span class="bandi-badge"><?= h($etichetteStato[$v['stato']] ?? $v['stato']) ?></span>
        <?php if ($v['codice_intervento'] !== ''): ?>
        <span class="cod">cod. <?= h($v['codice_intervento']) ?></span>
        <?php endif; ?>
        <span class="date">
          <?php if ($v['scadenza'] !== null): ?>scade <?= h(data_it($v['scadenza'])) ?>
          <?php else: ?>pubbl. <?= h(data_it($v['pubblicazione'])) ?><?php endif; ?>
          <?php if ($conto[0] !== ''): ?><span class="conto <?= h($conto[1]) ?>"><?= h($conto[0]) ?></span><?php endif; ?>
        </span>
      </div>
      <?php $tm = titolo_mostrato($v); ?>
      <h3><a href="<?= h($v['url_fonte']) ?>" target="_blank" rel="noopener"><?= h($tm) ?></a></h3>
      <?php if ($v['priorita'] !== '' && $v['priorita'] !== $v['titolo']): ?>
      <p class="prio"><?= h($v['priorita']) ?></p>
      <?php endif; ?>
      <?php if ($v['scopo'] !== '' && $v['scopo'] !== $v['titolo'] && ucfirst($v['scopo']) !== $tm): ?>
      <p><?= h($v['scopo']) ?></p>
      <?php endif; ?>
      <?php if ($v['nota'] !== ''): ?>
      <p class="nota"><?= h($v['nota']) ?></p>
      <?php endif; ?>
      <div class="foot">
        <a class="btn" href="<?= h($v['url_fonte']) ?>" target="_blank" rel="noopener">Scheda del bando ↗</a>
        <?php if ($v['url_ufficiale'] !== null): ?>
        <a class="btn btn-ufficiale" href="<?= h($v['url_ufficiale']) ?>" target="_blank" rel="noopener">Atto ufficiale ↗</a>
        <?php endif; ?>
        <?php if ($v['origine'] === 'istituzionale'):
            // I feed regionali non dicono da dove arrivano, e non serve: la
            // sezione è già quella della regione. Una fonte che invece si
            // nomina - l'archivio bandi della Campania, letto dalla sua
            // tabella e non da un feed di notizie - va distinta, perché ha un
            // valore diverso: porta gli estremi del decreto.
            $etichettaFonte = trim((string) ($v['fonte_label'] ?? '')); ?>
        <span class="prio"><?= $etichettaFonte !== ''
            ? h($etichettaFonte) . ', scadenza da verificare alla fonte'
            : 'segnalazione dal canale istituzionale regionale, scadenza da verificare alla fonte' ?></span>
        <?php elseif ($v['origine'] === 'flag'):
            // Alcuni FLAG hanno "FLAG" già nel proprio nome (es. "FLAG Riviera
            // Jonica Etnea"): senza questo controllo il testo raddoppierebbe
            // la parola ("segnalazione dal FLAG FLAG Riviera Jonica Etnea").
            $nomeFlag = (string) ($v['fonte_label'] ?? '');
            $prefisso = stripos($nomeFlag, 'FLAG') === 0 ? 'segnalazione dal ' : 'segnalazione dal FLAG ';
        ?>
        <span class="prio"><?= h($prefisso . $nomeFlag) ?>, scadenza da verificare alla fonte</span>
        <?php endif; ?>
        <?php if ($v['dettagli_mancanti'] && $v['scopo'] === ''): ?>
        <span class="incompleto">La fonte non pubblica una descrizione per questo bando: consultare la scheda o l'atto ufficiale.</span>
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
    </div>
  </details>
  <?php endforeach; ?>

  <?php endif; ?>

  <footer class="note">
    Pagina generata da <code>data/bandi.json</code>, aggiornato da <code>bandi_fetcher.php</code>
    una volta al giorno via Task Scheduler. L'archivio dei bandi chiusi non viene mai potato.
    Titoli e descrizioni appartengono alle fonti indicate: per il testo integrale seguire i
    collegamenti.
  </footer>

</div>

<script src="assets/filters.js"></script>
<script>
initFilters({
  chipAttr: "data-stato",
  itemAttr: "data-stato",
  containers: [".bandi-sec", ".bandi-aperti"],
  labels: { one: "bando trovato", many: "bandi trovati" }
});
// Le regioni si aprono col clic sul titolo. Un link con #sez-... apre quella regione;
// durante una ricerca si aprono tutte, così i risultati si vedono.
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
    document.querySelectorAll("details.bandi-sec").forEach(function (d) { d.open = true; });
  });
})();
</script>
<script src="assets/dinamica.js"></script>
</body>
</html>
