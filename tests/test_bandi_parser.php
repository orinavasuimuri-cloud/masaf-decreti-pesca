<?php
require_once __DIR__ . '/../lib/bandi_parser.php';

// --- archivio ---
$html = file_get_contents(__DIR__ . '/fixtures/bandi_archivio_toscana.html');
$voci = bandi_parse_archivio($html);

t_eq(count($voci), 10, 'archivio: 10 voci per pagina');

$v = $voci[0];
t_eq($v['origine'], 'aggregatore', 'archivio: origine valorizzata');
t_eq(strlen($v['id']), 40, 'archivio: id sha1');
t_true($v['wp_id'] > 0, 'archivio: wp_id estratto da id="post-N"');
t_true(in_array('toscana', $v['regioni'], true), 'archivio: regione dalla classe');
t_true(!in_array('terminato', $v['regioni'], true), 'archivio: terminato non è una regione');
t_true(str_starts_with($v['url_fonte'], 'https://www.feampabandionline.it/'), 'archivio: permalink assoluto');
t_true($v['titolo'] !== '', 'archivio: titolo non vuoto');
t_true($v['priorita'] !== '', 'archivio: priorità conservata come etichetta');
t_true($v['titolo'] !== $v['priorita'], 'archivio: il titolo non è la priorità');

// Almeno una voce della pagina deve avere scadenza e codice: se il markup cambia
// questi due campi sono i primi a sparire, e sono quelli che danno valore alla pagina.
$conScadenza = array_filter($voci, static fn(array $x): bool => $x['scadenza'] !== null);
$conCodice = array_filter($voci, static fn(array $x): bool => $x['codice_intervento'] !== '');
t_true(count($conScadenza) >= 5, 'archivio: almeno metà delle voci ha la scadenza');
t_true(count($conCodice) >= 5, 'archivio: almeno metà delle voci ha il codice di intervento');

// Un titolo di una sola parola segnala uno scopo troncato: il regex si e'
// fermato al primo <strong> di enfasi invece che al campo successivo.
foreach ($voci as $x) {
    t_true(strlen($x['titolo']) >= 15, 'archivio: titolo non troncato a una parola (' . $x['titolo'] . ')');
}

foreach ($voci as $x) {
    if ($x['scadenza'] !== null) {
        t_true(preg_match('/^\d{4}-\d{2}-\d{2}$/', $x['scadenza']) === 1, 'archivio: scadenza ISO');
    }
    if ($x['pubblicazione'] !== null) {
        t_true(preg_match('/^\d{4}-\d{2}-\d{2}$/', $x['pubblicazione']) === 1, 'archivio: pubblicazione ISO');
    }
}

$ids = array_column($voci, 'id');
t_eq(count($ids), count(array_unique($ids)), 'archivio: nessun id duplicato');
t_true(json_encode($voci) !== false, 'archivio: risultato codificabile in JSON');

// Il link al decreto regionale, quando presente, non deve puntare all'aggregatore.
foreach ($voci as $x) {
    if ($x['url_ufficiale'] !== null) {
        t_true(!str_contains($x['url_ufficiale'], 'feampabandionline.it'),
               'archivio: url_ufficiale è esterno alla fonte');
    }
}

// --- degrado controllato: markup senza i campi attesi ---
$rotto = '<html><body><article id="post-99" class="et_pb_post post-99 hentry category-lazio">'
       . '<h2 class="entry-title"><a href="https://www.feampabandionline.it/x/">Priorità 1</a></h2>'
       . '</article></body></html>';
$degradato = bandi_parse_archivio($rotto);
t_eq(count($degradato), 1, 'degrado: la voce resta anche senza campi');
t_eq($degradato[0]['scadenza'], null, 'degrado: scadenza nulla');
t_true($degradato[0]['dettagli_mancanti'], 'degrado: marcata come incompleta');
t_eq($degradato[0]['titolo'], 'Priorità 1', 'degrado: senza scopo il titolo ricade sulla priorità');

// --- scopo con enfasi inline: il regex deve fermarsi al campo successivo, non al primo <strong> ---
$conEnfasi = '<html><body><article id="post-500" class="et_pb_post post-500 hentry category-liguria">'
    . '<h2 class="entry-title"><a href="https://www.feampabandionline.it/y/">Priorita 9</a></h2>'
    . '<strong>Scopo Contributo:</strong> <p>favorire <strong>ammodernamento</strong> della flotta peschereccia in modo sostenibile</p>'
    . '<!-- inizio codice nuovo -->'
    . '<strong>Codice di intervento</strong><p>999999</p>'
    . '</article></body></html>';
$vociEnfasi = bandi_parse_archivio($conEnfasi);
t_eq(count($vociEnfasi), 1, 'enfasi: la voce viene estratta');
t_true(str_contains($vociEnfasi[0]['scopo'], 'ammodernamento'), 'enfasi: lo scopo include il testo enfatizzato con <strong>');
t_true(str_contains($vociEnfasi[0]['scopo'], 'flotta peschereccia'), 'enfasi: lo scopo continua dopo il tag di enfasi');
t_true(strlen($vociEnfasi[0]['titolo']) >= 15, 'enfasi: il titolo non resta troncato a una parola');

// --- pagina senza articoli: errore, non silenzio ---
$caught = false;
try { bandi_parse_archivio('<html><body><p>vuoto</p></body></html>'); }
catch (RuntimeException) { $caught = true; }
t_true($caught, 'archivio: pagina senza articoli solleva RuntimeException');

// --- categorie ---
$json = file_get_contents(__DIR__ . '/fixtures/bandi_categorie.json');
$cat = bandi_parse_categorie($json);
t_true(isset($cat['toscana']), 'categorie: slug regione presente');
t_true($cat['toscana'] > 0, 'categorie: conteggio positivo');
t_true(isset($cat['terminato']), 'categorie: terminato presente per la riconciliazione');
$caught2 = false;
try { bandi_parse_categorie('non json'); } catch (RuntimeException) { $caught2 = true; }
t_true($caught2, 'categorie: JSON non valido solleva RuntimeException');

// --- feed istituzionali ---
$feedItems = [
    ['id' => 'a1', 'source' => 'f', 'title' => 'Bando pesca costiera artigianale',
     'url' => 'https://x.it/a1', 'date' => '2026-07-20T10:00:00+02:00', 'summary' => 'Estratto'],
    ['id' => 'a2', 'source' => 'f', 'title' => 'Concorso per bibliotecari',
     'url' => 'https://x.it/a2', 'date' => '2026-07-21T10:00:00+02:00', 'summary' => ''],
];
$filtrati = bandi_da_feed($feedItems, 'calabria', ['pesca', 'ittic']);
t_eq(count($filtrati), 1, 'feed: filtro per parole chiave applicato');
t_eq($filtrati[0]['origine'], 'istituzionale', 'feed: origine istituzionale');
t_eq($filtrati[0]['regioni'], ['calabria'], 'feed: regione dalla configurazione');
t_eq($filtrati[0]['scadenza'], null, 'feed: nessuna scadenza dichiarata');
t_true($filtrati[0]['dettagli_mancanti'], 'feed: segnalazione, non scheda completa');
t_eq($filtrati[0]['pubblicazione'], '2026-07-20', 'feed: data ridotta al giorno');
t_eq($filtrati[0]['titolo'], 'Bando pesca costiera artigianale', 'feed: titolo conservato');

$tutti = bandi_da_feed($feedItems, 'basilicata', []);
t_eq(count($tutti), 2, 'feed: senza parole chiave passano tutte le voci');
