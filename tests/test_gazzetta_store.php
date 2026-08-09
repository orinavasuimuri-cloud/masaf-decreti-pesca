<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/gazzetta_store.php';

// --- numeri saltati ---
t_eq(gazzetta_numeri_saltati(176, 178), [177], 'gazzetta: il numero saltato non viene riconosciuto');
t_eq(gazzetta_numeri_saltati(177, 178), [], 'gazzetta: due numeri consecutivi non hanno salti');
t_eq(gazzetta_numeri_saltati(178, 178), [], 'gazzetta: rileggere lo stesso numero non e un salto');
t_eq(gazzetta_numeri_saltati(null, 178), [], 'gazzetta: alla prima esecuzione non si puo parlare di salti');
// Il numero riparte da 1 ogni anno solare: un salto all'indietro a gennaio e'
// normale e segnalarlo insegnerebbe a ignorare le segnalazioni.
t_eq(gazzetta_numeri_saltati(250, 1), [], 'gazzetta: il cambio d anno non e un salto');
t_eq(gazzetta_numeri_saltati(170, 175), [171, 172, 173, 174], 'gazzetta: salto multiplo non elencato per intero');

// --- archivio vuoto e merge ---
$vuoto = gazzetta_store_empty();
t_eq($vuoto['items'], [], 'gazzetta: archivio vuoto deve avere items vuoto');

$voce = [
    'id' => '26A03900', 'serie' => 'gu-sg', 'emittente' => 'MINISTERO', 'tipo_atto' => 'DECRETO',
    'titolo' => 'MINISTERO - DECRETO 15 luglio 2026', 'oggetto' => 'Interruzione della pesca. (26A03900)',
    'url' => 'http://www.gazzettaufficiale.it/eli/id/2026/08/03/26A03900/SG',
    'numero_gu' => 178, 'data_gu' => '2026-08-03', 'destinazione' => 'registro',
    'status' => 'pending_review', 'first_seen' => '2026-08-04',
];
$s = gazzetta_store_merge($vuoto, 'gu-sg', [$voce], 178, '2026-08-03', '2026-08-04T15:00:00+02:00');
t_eq(count($s['items']), 1, 'gazzetta: la voce non e stata inserita');
t_eq($s['_meta']['serie']['gu-sg']['ultimo_numero'], 178, 'gazzetta: ultimo numero non registrato');
t_eq($s['_meta']['serie']['gu-sg']['errore'], null, 'gazzetta: un merge riuscito non deve lasciare errori');

// Lo stato assegnato a mano dal curatore non deve essere sovrascritto da un
// secondo passaggio della stessa voce: e' l'unica informazione che la fonte
// non possiede.
$s['items']['26A03900']['status'] = 'curated';
$s2 = gazzetta_store_merge($s, 'gu-sg', [$voce], 179, '2026-08-04', '2026-08-05T15:00:00+02:00');
t_eq($s2['items']['26A03900']['status'], 'curated', 'gazzetta: il merge ha sovrascritto uno stato deciso a mano');
t_eq(count($s2['items']), 1, 'gazzetta: la stessa voce e stata duplicata');

// --- fallimento di una serie ---
$sf = gazzetta_store_mark_failure($s2, 'gu-sg', 'download fallito', '2026-08-05T15:00:00+02:00');
t_eq($sf['_meta']['serie']['gu-sg']['errore'], 'download fallito', 'gazzetta: errore non registrato');
t_eq($sf['_meta']['serie']['gu-sg']['ultimo_numero'], 179, 'gazzetta: un fallimento non deve perdere l ultimo numero visto');

// --- caricamento ---
$tmp = sys_get_temp_dir() . '/gazzetta_test_' . getmypid() . '.json';
@unlink($tmp);
t_eq(gazzetta_store_load($tmp)['items'], [], 'gazzetta: un file assente deve dare archivio vuoto');
gazzetta_store_save($tmp, $s2);
t_eq(count(gazzetta_store_load($tmp)['items']), 1, 'gazzetta: la voce non sopravvive al salvataggio');
// File presente ma corrotto e' un guasto, non un archivio vuoto: degradare qui
// farebbe riscrivere l'archivio buono con il nulla al run successivo.
file_put_contents($tmp, '{"items": tronc');
$corrotto = false;
try {
    gazzetta_store_load($tmp);
} catch (RuntimeException) {
    $corrotto = true;
}
t_true($corrotto, 'gazzetta: un archivio corrotto deve lanciare RuntimeException');

// JSON valido ma _meta della forma sbagliata: sarebbe passato inosservato fino
// al merge, che sarebbe morto lontano dalla causa e senza dire quale file.
foreach ([
    '{"items": {}, "_meta": "non un oggetto"}'            => '_meta non un oggetto',
    '{"items": {}, "_meta": {"serie": "non un oggetto"}}' => '_meta.serie non un oggetto',
] as $contenuto => $caso) {
    file_put_contents($tmp, $contenuto);
    $malformato = false;
    try {
        gazzetta_store_load($tmp);
    } catch (RuntimeException) {
        $malformato = true;
    }
    t_true($malformato, "gazzetta: $caso deve lanciare RuntimeException");
}
@unlink($tmp);

// --- travaso verso i bandi: ripartire dall'archivio, non dal giro corrente ---
// Prima si travasavano le sole voci lette nel fascicolo appena scaricato: se il
// salvataggio dei bandi falliva, al giro dopo quelle voci non erano piu' fra le
// "appena lette" e nessuno le riprovava. Questi controlli tengono fermo il
// comportamento nuovo, che riparte da cio' che l'archivio dice non pubblicato.
$daTravasare = [
    'A1' => ['id' => 'A1', 'destinazione' => 'bandi',    'travasato' => false],
    'A2' => ['id' => 'A2', 'destinazione' => 'bandi',    'travasato' => true],
    'A3' => ['id' => 'A3', 'destinazione' => 'registro', 'travasato' => false],
    // Voce salvata prima che il campo esistesse: va considerata da travasare,
    // non gia' fatta, altrimenti l'introduzione del campo le perderebbe tutte.
    'A4' => ['id' => 'A4', 'destinazione' => 'bandi'],
];
$attesi = gazzetta_da_travasare($daTravasare);
t_eq(array_column($attesi, 'id'), ['A1', 'A4'], 'gazzetta: da travasare deve tenere solo i bandi non ancora pubblicati');
t_eq(gazzetta_da_travasare([]), [], 'gazzetta: un archivio vuoto non ha nulla da travasare');

$marcato = gazzetta_marca_travasate(['items' => $daTravasare], ['A1', 'A4']);
t_eq(gazzetta_da_travasare($marcato['items']), [], 'gazzetta: dopo la marcatura non deve restare nulla da travasare');
// Un id che non c'e' non deve creare voci fantasma: il travaso potrebbe averlo
// letto da un archivio piu' vecchio.
$ignoto = gazzetta_marca_travasate(['items' => $daTravasare], ['MAI_VISTO']);
t_eq(count($ignoto['items']), 4, 'gazzetta: marcare un id assente non deve aggiungere voci');

// Il merge non deve riazzerare il flag: rileggere lo stesso fascicolo e' la
// norma (il feed espone un numero per giorno, il job gira piu' spesso), e senza
// questa protezione ogni riletura ritravaserebbe le stesse voci.
$vocePubblicata = [
    'id' => 'B1', 'serie' => 'gu-sg', 'emittente' => 'MINISTERO', 'tipo_atto' => 'DECRETO',
    'titolo' => 'MINISTERO - DECRETO', 'oggetto' => 'Bando pesca. (B1)',
    'url' => 'http://esempio/B1', 'numero_gu' => 180, 'data_gu' => '2026-08-05',
    'destinazione' => 'bandi', 'status' => 'pending_review', 'first_seen' => '2026-08-05',
    'travasato' => false,
];
$sb = gazzetta_store_merge(gazzetta_store_empty(), 'gu-sg', [$vocePubblicata], 180, '2026-08-05', '2026-08-05T10:00:00+02:00');
$sb = gazzetta_marca_travasate($sb, ['B1']);
$sb = gazzetta_store_merge($sb, 'gu-sg', [$vocePubblicata], 180, '2026-08-05', '2026-08-05T11:00:00+02:00');
t_eq($sb['items']['B1']['travasato'], true, 'gazzetta: il merge non deve riportare a false una voce gia travasata');

// --- feed fermo ---
// gazzetta_numeri_saltati() vede i buchi, non la stagnazione: una fonte che
// risponde sempre con lo stesso sommario non salta nulla e passerebbe inosservata.
t_eq(gazzetta_feed_fermo('2026-08-05', '2026-08-06'), false, 'gazzetta: un fascicolo di ieri non e una fonte ferma');
t_eq(gazzetta_feed_fermo('2026-08-02', '2026-08-06'), false, 'gazzetta: quattro giorni sono ancora dentro la soglia');
t_eq(gazzetta_feed_fermo('2026-08-01', '2026-08-06'), true, 'gazzetta: cinque giorni sullo stesso fascicolo vanno segnalati');
t_eq(gazzetta_feed_fermo(null, '2026-08-06'), false, 'gazzetta: senza una data precedente non si puo parlare di fonte ferma');
t_eq(gazzetta_feed_fermo('', '2026-08-06'), false, 'gazzetta: una data vuota non e una fonte ferma');
t_eq(gazzetta_feed_fermo('non-una-data', '2026-08-06'), false, 'gazzetta: una data illeggibile non deve produrre un falso allarme');
t_eq(gazzetta_feed_fermo('2026-08-01', '2026-08-06', 10), false, 'gazzetta: la soglia deve essere configurabile');

// --- recupero di un fascicolo arretrato ---
// Il rischio da coprire non e' che le voci non entrino, ma che il recupero si
// dichiari ultimo fascicolo visto: riportare indietro 'ultimo_numero' farebbe
// ricomparire come saltati i fascicoli gia' letti, all'infinito.
$conBuchi = gazzetta_store_empty();
$conBuchi = gazzetta_store_merge($conBuchi, 'gu-sg', [], 183, '2026-08-08', '2026-08-09T21:00:00+02:00');
$conBuchi['_meta']['serie']['gu-sg']['saltati'] = [181, 182];

$vociArretrate = [[
    'id' => '26A03910', 'serie' => 'gu-sg', 'numero_gu' => 181, 'data_gu' => '2026-08-06',
    'titolo' => 'X', 'oggetto' => 'Y', 'url' => 'https://esempio/1',
    'status' => 'pending_review', 'destinazione' => 'registro', 'first_seen' => '2026-08-09', 'travasato' => false,
]];
$dopoRecupero = gazzetta_store_recupera($conBuchi, 'gu-sg', $vociArretrate, 181);

t_true(isset($dopoRecupero['items']['26A03910']), 'gazzetta recupero: la voce arretrata non e finita in archivio');
t_eq($dopoRecupero['_meta']['serie']['gu-sg']['saltati'], [182], 'gazzetta recupero: il fascicolo letto non e stato tolto dai saltati');
t_eq($dopoRecupero['_meta']['serie']['gu-sg']['ultimo_numero'], 183, 'gazzetta recupero: l ultimo numero visto non deve tornare indietro');
t_eq($dopoRecupero['_meta']['serie']['gu-sg']['ultima_data'], '2026-08-08', 'gazzetta recupero: l ultima data non deve tornare indietro');
t_eq($dopoRecupero['_meta']['serie']['gu-sg']['last_ok'], '2026-08-09T21:00:00+02:00', 'gazzetta recupero: last_ok dice quando ha risposto il feed, non il recupero');

// Rileggendo lo stesso fascicolo, quello che sappiamo noi resta nostro: se il
// curatore ha gia' valutato la voce, un secondo recupero non la rimette in coda.
$dopoRecupero['items']['26A03910']['status'] = 'curated';
$dopoRecupero['items']['26A03910']['travasato'] = true;
$dueVolte = gazzetta_store_recupera($dopoRecupero, 'gu-sg', $vociArretrate, 181);
t_eq($dueVolte['items']['26A03910']['status'], 'curated', 'gazzetta recupero: un secondo passaggio ha riportato la voce da rivedere');
t_eq($dueVolte['items']['26A03910']['travasato'], true, 'gazzetta recupero: un secondo passaggio farebbe ritravasare la voce');
t_eq($dueVolte['_meta']['serie']['gu-sg']['saltati'], [182], 'gazzetta recupero: togliere un numero gia tolto non deve toccare il resto');

// --- i saltati si annotano con l'anno ---
// Senza anno, un numero rimasto in elenco tornerebbe buono per il fascicolo
// omonimo dell'anno successivo: stesso numero, contenuto di dodici mesi dopo, e
// nemmeno il controllo sul numero restituito se ne accorgerebbe.
t_eq(gazzetta_saltato_chiave(181, '2026'), '2026/181', 'gazzetta saltati: chiave non composta come anno/numero');
t_eq(gazzetta_saltato_scomponi('2026/181', '1999'), ['numero' => 181, 'anno' => '2026'], 'gazzetta saltati: chiave non riletta');
t_eq(gazzetta_saltato_scomponi(181, '2026'), ['numero' => 181, 'anno' => '2026'], 'gazzetta saltati: il vecchio formato deve valere per l anno di ripiego');

$conAnno = gazzetta_store_empty();
$conAnno = gazzetta_store_merge($conAnno, 'gu-sg', [], 3, '2027-01-05', '2027-01-05T07:00:00+01:00');
$conAnno['_meta']['serie']['gu-sg']['saltati'] = ['2026/305', '2027/2'];

$vociDue = [[
    'id' => '27A00002', 'serie' => 'gu-sg', 'numero_gu' => 2, 'data_gu' => '2027-01-04',
    'titolo' => 'X', 'oggetto' => 'Y', 'url' => 'https://esempio/2',
    'status' => 'pending_review', 'destinazione' => 'registro', 'first_seen' => '2027-01-05', 'travasato' => false,
]];
$dopoDue = gazzetta_store_recupera($conAnno, 'gu-sg', $vociDue, '2027/2', '2027');
t_eq($dopoDue['_meta']['serie']['gu-sg']['saltati'], ['2026/305'], 'gazzetta saltati: recuperando 2027/2 non deve sparire 2026/305');

// Lo stesso numero di un altro anno non e' lo stesso fascicolo.
$dopoAltroAnno = gazzetta_store_recupera($conAnno, 'gu-sg', [], '2027/305', '2027');
t_eq($dopoAltroAnno['_meta']['serie']['gu-sg']['saltati'], ['2026/305', '2027/2'], 'gazzetta saltati: 2027/305 non deve togliere 2026/305');
