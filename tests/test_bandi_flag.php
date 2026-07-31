<?php
require_once __DIR__ . '/../lib/bandi_parser.php';
require_once __DIR__ . '/../lib/bandi_store.php';

// --- bandi_da_feed(): i parametri nuovi sono opzionali e non toccano il
// comportamento di default già coperto da tests/test_bandi_parser.php ---
$feedItems = [
    ['id' => 'a1', 'source' => 'f', 'title' => 'Bando pesca costiera artigianale',
     'url' => 'https://x.it/a1', 'date' => '2026-07-20T10:00:00+02:00', 'summary' => 'Estratto'],
];
$default = bandi_da_feed($feedItems, 'calabria', ['pesca']);
t_eq($default[0]['origine'], 'istituzionale', 'default: origine istituzionale se non specificata');
t_eq($default[0]['fonte_label'], '', 'default: fonte_label vuota se non specificata');

// --- bandi_da_feed() con origine e provenienza esplicite: il caso dei FLAG ---
$flagItems = [
    ['id' => 'b1', 'source' => 'flag-test', 'title' => 'Avviso pubblico Azione 1.1 della SSL',
     'url' => 'https://galtest.it/b1', 'date' => '2026-07-25T09:00:00+02:00', 'summary' => ''],
    ['id' => 'b2', 'source' => 'flag-test', 'title' => 'Il GAL al seminario europeo di Tallinn',
     'url' => 'https://galtest.it/b2', 'date' => '2026-07-26T09:00:00+02:00', 'summary' => ''],
];
$flagKeywords = ['band', 'avviso', 'graduatoria', 'proroga', 'interesse', 'contribut', 'FEAMPA'];
$flagVoci = bandi_da_feed($flagItems, 'lazio', $flagKeywords, 'flag', 'GAL Test Pesca');
t_eq(count($flagVoci), 1, 'flag: filtro parole chiave applicato come per i feed istituzionali');
t_eq($flagVoci[0]['origine'], 'flag', 'flag: origine "flag" quando richiesta esplicitamente');
t_eq($flagVoci[0]['fonte_label'], 'GAL Test Pesca', 'flag: fonte_label valorizzata con il nome del FLAG');
t_eq($flagVoci[0]['regioni'], ['lazio'], 'flag: regione dalla configurazione della fonte');
t_eq($flagVoci[0]['scadenza'], null, 'flag: nessuna scadenza dichiarata, come le segnalazioni istituzionali');
t_true($flagVoci[0]['dettagli_mancanti'], 'flag: segnalazione, non scheda completa');

// Il filtro "interesse" (non "manifestazione di interesse" letterale) intercetta
// anche la forma con apostrofo curvo "manifestazione d'interesse", osservata sui
// feed reali (es. Approdo di Ulisse): la sola frase esatta la perderebbe.
$apostrofo = [
    ['id' => 'c1', 'source' => 'flag-test', 'title' => "Raccolta di manifestazioni d\u{2019}interesse per short list",
     'url' => 'https://galtest.it/c1', 'date' => '2026-07-27T09:00:00+02:00', 'summary' => ''],
];
$apostrofoVoci = bandi_da_feed($apostrofo, 'lazio', $flagKeywords, 'flag', 'GAL Test Pesca');
t_eq(count($apostrofoVoci), 1, 'flag: manifestazione d\'interesse con apostrofo curvo intercettata dal filtro');

// Regressione sulla scelta dello stem "band" al posto di "bando": i titoli
// reali dei feed usano quasi sempre il plurale ("Pubblicati due bandi per 690
// mila euro..."), che la forma singolare "bando" non intercetta. Questo test
// deve fallire se lo stem torna "bando": è la prova che la scelta ha mordente.
$plurale = [
    ['id' => 'd1', 'source' => 'flag-test', 'title' => 'Pubblicati due bandi per 690 mila euro a sostegno della pesca',
     'url' => 'https://galtest.it/d1', 'date' => '2026-07-28T09:00:00+02:00', 'summary' => ''],
];
$pluraleVoci = bandi_da_feed($plurale, 'lazio', $flagKeywords, 'flag', 'GAL Test Pesca');
t_eq(count($pluraleVoci), 1, 'flag: titolo con "bandi" al plurale (senza "bando") intercettato dallo stem "band"');

// --- bandi_voce(): il campo fonte_label esiste sempre, di default vuoto ---
$vuota = bandi_voce(['id' => 'x', 'origine' => 'aggregatore', 'url_fonte' => 'https://x.it/x']);
t_eq($vuota['fonte_label'], '', 'bandi_voce: fonte_label di default vuota');
$valorizzata = bandi_voce(['id' => 'y', 'origine' => 'flag', 'url_fonte' => 'https://x.it/y', 'fonte_label' => 'GAL Y']);
t_eq($valorizzata['fonte_label'], 'GAL Y', 'bandi_voce: fonte_label passata esplicitamente');

// --- bandi_store_merge(): il campo si propaga e si aggiorna come gli altri ---
$now = '2026-07-31T09:00:00+02:00';
$mkFlag = static fn(string $id, string $label): array => bandi_voce([
    'id' => $id, 'origine' => 'flag', 'regioni' => ['toscana'], 'titolo' => "t$id",
    'pubblicazione' => '2026-07-01', 'url_fonte' => "https://galtest.it/$id",
    'dettagli_mancanti' => true, 'fonte_label' => $label,
]);
$store = bandi_store_merge(bandi_store_empty(), 'flag-toscana', [$mkFlag('f1', 'GALPA Toscana')], $now);
t_eq($store['items'][0]['fonte_label'], 'GALPA Toscana', 'store: fonte_label salvata al primo merge');
$store2 = bandi_store_merge($store, 'flag-toscana', [$mkFlag('f1', 'GALPA Toscana (rinominato)')], $now);
t_eq($store2['items'][0]['fonte_label'], 'GALPA Toscana (rinominato)', 'store: fonte_label aggiornata su un merge successivo');

// Una voce salvata prima di questa funzionalità (senza la chiave fonte_label)
// non deve far fallire il merge né perdere l'aggiornamento degli altri campi:
// è esattamente il caso di data/bandi.json prima della prima esecuzione col
// codice nuovo.
$storeVecchio = bandi_store_empty();
$storeVecchio['items'][] = [
    'id' => 'g1', 'wp_id' => 0, 'origine' => 'flag', 'regioni' => ['toscana'],
    'priorita' => '', 'titolo' => 'vecchio', 'scopo' => '', 'codice_intervento' => '',
    'pubblicazione' => '2026-06-01', 'scadenza' => null, 'terminato_in_fonte' => false,
    'nota' => '', 'url_fonte' => 'https://galtest.it/g1', 'url_ufficiale' => null,
    'dettagli_mancanti' => true,
    // niente 'fonte_label' qui: simula un record scritto dal codice precedente
];
$storeVecchio['_meta']['fonti']['flag-toscana'] = ['last_ok' => null, 'last_error' => null, 'consecutive_failures' => 0];
$storeAggiornato = bandi_store_merge($storeVecchio, 'flag-toscana', [$mkFlag('g1', 'GALPA Toscana')], $now);
$g1 = null;
foreach ($storeAggiornato['items'] as $it) { if ($it['id'] === 'g1') { $g1 = $it; } }
t_eq($g1['fonte_label'], 'GALPA Toscana', 'store: la voce pre-esistente senza fonte_label viene aggiornata senza errori');
t_eq($g1['titolo'], 'tg1', 'store: gli altri campi si aggiornano come prima');

// --- configurazione: data/bandi_fonti.json e data/bandi_regioni.json ---
$fontiPath = __DIR__ . '/../data/bandi_fonti.json';
$fonti = json_decode((string) file_get_contents($fontiPath), true);
t_true(is_array($fonti) && isset($fonti['flag']) && is_array($fonti['flag']) && $fonti['flag'] !== [],
    'config: data/bandi_fonti.json ha una lista "flag" non vuota');

$regioniPath = __DIR__ . '/../data/bandi_regioni.json';
$regioni = json_decode((string) file_get_contents($regioniPath), true);
$slugRegioni = array_column($regioni['regioni'], 'slug');

$idsVisti = [];
foreach ($fonti['flag'] as $f) {
    foreach (['id', 'label', 'url', 'regione', 'keywords'] as $chiave) {
        t_true(isset($f[$chiave]), "config flag: campo \"$chiave\" presente su {$f['id']}");
    }
    t_true(!isset($idsVisti[$f['id']]), "config flag: id \"{$f['id']}\" univoco");
    $idsVisti[$f['id']] = true;
    t_true(str_starts_with((string) $f['url'], 'https://'), "config flag: url https su {$f['id']}");
    t_true(in_array($f['regione'], $slugRegioni, true),
        "config flag: regione \"{$f['regione']}\" esiste in bandi_regioni.json ({$f['id']})");
}

// Il FLAG Magna Graecia deve puntare al sito proprio (corretto in questo lavoro),
// non più alla scheda sul sito della Regione Campania.
$campania = null;
foreach ($regioni['regioni'] as $r) { if ($r['slug'] === 'campania') { $campania = $r; } }
t_true($campania !== null, 'config regioni: campania presente');
$magnaGraecia = null;
foreach ($campania['flag'] as $f) { if (str_contains($f['nome'], 'Magna Graecia')) { $magnaGraecia = $f; } }
t_true($magnaGraecia !== null, 'config regioni: FLAG Magna Graecia presente per la Campania');
t_true(str_contains($magnaGraecia['url'], 'galpescamagnagraecia.it'),
    'config regioni: URL Magna Graecia corretto al sito proprio, non più alla scheda regionale');

// Stessa correzione applicata anche agli altri due FLAG campani: sito proprio,
// non più la scheda sul sito della Regione Campania.
$approdoDiUlisse = null;
foreach ($campania['flag'] as $f) { if (str_contains($f['nome'], 'Approdo di Ulisse')) { $approdoDiUlisse = $f; } }
t_true($approdoDiUlisse !== null, 'config regioni: FLAG Approdo di Ulisse presente per la Campania');
t_true(str_contains($approdoDiUlisse['url'], 'flagapprododiulisse.it'),
    'config regioni: URL Approdo di Ulisse corretto al sito proprio, non più alla scheda regionale');

$parthenope = null;
foreach ($campania['flag'] as $f) { if (str_contains($f['nome'], 'Parthenope')) { $parthenope = $f; } }
t_true($parthenope !== null, 'config regioni: FLAG Parthenope presente per la Campania');
t_true(str_contains($parthenope['url'], 'galparthenope.it'),
    'config regioni: URL Parthenope corretto al sito proprio, non più alla scheda regionale');
