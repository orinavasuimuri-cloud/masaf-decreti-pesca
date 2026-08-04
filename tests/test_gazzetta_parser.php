<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/gazzetta_parser.php';

$xml = (string) file_get_contents(__DIR__ . '/fixtures/gazzetta_sg.xml');

// --- lettura del sommario ---
$sommario = gazzetta_parse_sommario($xml);
t_eq($sommario['numero'], 178, 'gazzetta: numero del fascicolo non letto dalla description del canale');
t_eq($sommario['data'], '2026-08-03', 'gazzetta: data del fascicolo non convertita in ISO');
t_eq(count($sommario['items']), 6, 'gazzetta: non tutti gli atti del sommario sono stati letti');
t_eq(
    $sommario['items'][0]['titolo'],
    "MINISTERO DELL'AGRICOLTURA, DELLA SOVRANITA' ALIMENTARE E DELLE FORESTE - DECRETO 24 giugno 2026",
    'gazzetta: titolo del primo atto non letto'
);
t_true(
    str_starts_with($sommario['items'][0]['oggetto'], 'Fondo Alimentare 2026 e 2027.'),
    'gazzetta: oggetto non letto da content:encoded'
);
t_eq(
    $sommario['items'][0]['url'],
    'http://www.gazzettaufficiale.it/eli/id/2026/08/03/26A03853/SG',
    'gazzetta: URL ELI dell atto non letto'
);

// Un XML che non e' un sommario deve fallire in modo esplicito, non restituire
// un sommario vuoto: un feed cambiato in silenzio e' un guasto da vedere.
$rotto = false;
try {
    gazzetta_parse_sommario('<rss><channel><title>vuoto</title></channel></rss>');
} catch (RuntimeException) {
    $rotto = true;
}
t_true($rotto, 'gazzetta: un sommario senza item deve lanciare RuntimeException');

// --- scomposizione del titolo ---
// Si separa sull'ULTIMO " - ": la Presidenza del Consiglio ne usa due, e
// spezzare sul primo attribuirebbe l'atto al dipartimento sbagliato.
$a = gazzetta_scompone_titolo("MINISTERO DELL'AGRICOLTURA, DELLA SOVRANITA' ALIMENTARE E DELLE FORESTE - DECRETO 24 giugno 2026");
t_eq($a['emittente'], "MINISTERO DELL'AGRICOLTURA, DELLA SOVRANITA' ALIMENTARE E DELLE FORESTE", 'gazzetta: emittente non estratto');
t_eq($a['tipo_atto'], 'DECRETO', 'gazzetta: tipo atto non estratto');

$b = gazzetta_scompone_titolo('PRESIDENZA DEL CONSIGLIO DEI MINISTRI - DIPARTIMENTO PER LA TRASFORMAZIONE DIGITALE - DECRETO 19 marzo 2026');
t_eq($b['emittente'], 'PRESIDENZA DEL CONSIGLIO DEI MINISTRI - DIPARTIMENTO PER LA TRASFORMAZIONE DIGITALE', 'gazzetta: con due separatori l emittente deve tenerli entrambi');
t_eq($b['tipo_atto'], 'DECRETO', 'gazzetta: tipo atto non estratto con due separatori');

// Senza separatore sono atti dello Stato: emittente vuoto, non errore.
$c = gazzetta_scompone_titolo('DECRETO LEGISLATIVO 26 giugno 2026, n.138');
t_eq($c['emittente'], '', 'gazzetta: senza separatore l emittente deve essere vuoto');
t_eq($c['tipo_atto'], 'DECRETO LEGISLATIVO', 'gazzetta: tipo atto su piu parole non estratto');

$d = gazzetta_scompone_titolo('AGENZIA ITALIANA DEL FARMACO - COMUNICATO');
t_eq($d['tipo_atto'], 'COMUNICATO', 'gazzetta: tipo atto senza data non estratto');

// Il decreto-legge porta il trattino dentro il nome dell'atto: senza il
// trattino nella classe il match fallisce del tutto e il tipo si riempie
// della coda intera, data e numero compresi.
$e = gazzetta_scompone_titolo('DECRETO-LEGGE 1 gennaio 2026, n.1');
t_eq($e['tipo_atto'], 'DECRETO-LEGGE', 'gazzetta: tipo atto col trattino non estratto');

$f = gazzetta_scompone_titolo('TESTO COORDINATO DEL DECRETO-LEGGE 30 aprile 2026, n. 55');
t_eq($f['tipo_atto'], 'TESTO COORDINATO DEL DECRETO-LEGGE', 'gazzetta: tipo atto composto col trattino non estratto');

// --- codice dell'atto ---
t_eq(
    gazzetta_codice_atto('Fondo Alimentare 2026 e 2027. (26A03853)', 'http://www.gazzettaufficiale.it/eli/id/2026/08/03/26A03853/SG'),
    '26A03853',
    'gazzetta: codice atto non letto dalla coda dell oggetto'
);
// Quando l'oggetto non lo porta si ricade sull'URL ELI.
t_eq(
    gazzetta_codice_atto('Oggetto senza codice.', 'http://www.gazzettaufficiale.it/eli/id/2026/08/03/26A03812/SG'),
    '26A03812',
    'gazzetta: codice atto non recuperato dall URL ELI'
);
// Senza identificatore stabile la voce non e' registrabile: ogni run la
// ripresenterebbe come nuova. Stringa vuota, e chi chiama la scarta.
t_eq(
    gazzetta_codice_atto('Oggetto senza codice.', 'http://www.gazzettaufficiale.it/qualcosa/altro'),
    '',
    'gazzetta: senza codice ricavabile si deve restituire stringa vuota'
);

// --- filtro in tema ---
$kw = ['pesca', 'pescher', 'ittic', 'acquacolt', 'mollusch', 'vongol', 'tonno', 'FEAMPA', 'GSA', 'marittim'];

// Regressione dal campo: nel numero 178 il MASAF ha pubblicato due decreti,
// entrambi agricoli. Filtrare per solo emittente li farebbe entrare.
t_eq(
    gazzetta_in_tema(
        "MINISTERO DELL'AGRICOLTURA, DELLA SOVRANITA' ALIMENTARE E DELLE FORESTE - DECRETO 24 giugno 2026",
        'Fondo Alimentare 2026 e 2027. Individuazione dei beneficiari del contributo economico. (26A03853)',
        $kw
    ),
    false,
    'gazzetta: il decreto Fondo Alimentare non e in tema e non deve passare il filtro'
);
t_eq(
    gazzetta_in_tema(
        "MINISTERO DELL'AGRICOLTURA, DELLA SOVRANITA' ALIMENTARE E DELLE FORESTE - DECRETO 23 luglio 2026",
        "Dichiarazione dell'esistenza del carattere di eccezionalita' delle avversita' atmosferiche nella Regione Molise. (26A03812)",
        $kw
    ),
    false,
    'gazzetta: la declaratoria meteo non e in tema e non deve passare il filtro'
);
// Il titolo non porta mai la materia: se il filtro guardasse solo li', questo
// decreto sulla pesca non verrebbe mai trovato.
t_eq(
    gazzetta_in_tema(
        "MINISTERO DELL'AGRICOLTURA, DELLA SOVRANITA' ALIMENTARE E DELLE FORESTE - DECRETO 15 luglio 2026",
        "Disposizioni in materia di interruzione temporanea obbligatoria della pesca a strascico nelle GSA 17 e 18. (26A03900)",
        $kw
    ),
    true,
    'gazzetta: un decreto sulla pesca deve essere trovato dall oggetto anche se il titolo tace'
);

// --- destinazione ---
t_eq(
    gazzetta_destinazione("Avviso pubblico per la presentazione delle domande di contributo alle imprese di acquacoltura."),
    'bandi',
    'gazzetta: un avviso pubblico deve andare ai bandi'
);
t_eq(
    gazzetta_destinazione("Disposizioni in materia di interruzione temporanea obbligatoria della pesca a strascico."),
    'registro',
    'gazzetta: un decreto dispositivo deve andare al registro'
);
// "contributo" da solo e' un segnale troppo debole: nel numero 178 compare in
// un decreto che individua beneficiari, che e' un atto, non un avviso a cui ci
// si candida. Il dubbio va al registro, che ha un cancello umano.
t_eq(
    gazzetta_destinazione("Individuazione dei beneficiari del contributo economico previsto dalla legge."),
    'registro',
    'gazzetta: il solo contributo non basta a fare un bando'
);

// --- robustezza della ricerca ---
// Una parola chiave che contiene il delimitatore non deve rompere la ricerca:
// senza protezione l'espressione diventa malformata e scarta tutto in silenzio.
t_eq(
    gazzetta_in_tema('titolo qualunque', 'decreto sulla pesca a strascico', ['pesca', 'acqua/mare']),
    true,
    'gazzetta: una parola chiave col delimitatore non deve invalidare la ricerca'
);

// Un guasto della ricerca si deve vedere: restituire false lo confonderebbe
// con "nessuna corrispondenza" e la fonte sembrerebbe solo priva di notizie.
$rottaLaRicerca = false;
try {
    gazzetta_corrisponde('un testo qualunque', ["\xC3\x28"]);
} catch (RuntimeException) {
    $rottaLaRicerca = true;
}
t_true($rottaLaRicerca, 'gazzetta: un espressione di ricerca non compilabile deve lanciare RuntimeException');

// --- voci complete dal sommario ---
$voci = gazzetta_voci($sommario, 'gu-sg', $kw, '2026-08-04');
t_eq(count($voci), 2, 'gazzetta: dal sommario di prova devono uscire due sole voci in tema');
$perCodice = [];
foreach ($voci as $v) {
    $perCodice[$v['id']] = $v;
}
t_true(isset($perCodice['26A03900']), 'gazzetta: il decreto sul fermo non e fra le voci prodotte');
t_eq($perCodice['26A03900']['destinazione'], 'registro', 'gazzetta: il decreto sul fermo deve andare al registro');
t_eq($perCodice['26A03900']['tipo_atto'], 'DECRETO', 'gazzetta: tipo atto non riportato nella voce');
t_eq($perCodice['26A03900']['numero_gu'], 178, 'gazzetta: numero del fascicolo non riportato nella voce');
t_eq($perCodice['26A03900']['data_gu'], '2026-08-03', 'gazzetta: data del fascicolo non riportata nella voce');
t_eq($perCodice['26A03900']['serie'], 'gu-sg', 'gazzetta: id della serie non riportato nella voce');
t_eq($perCodice['26A03900']['status'], 'pending_review', 'gazzetta: una voce nuova deve nascere da rivedere');
t_eq($perCodice['26A03900']['first_seen'], '2026-08-04', 'gazzetta: data di primo avvistamento non riportata');
t_true(isset($perCodice['26A03901']), 'gazzetta: l avviso pubblico non e fra le voci prodotte');
t_eq($perCodice['26A03901']['destinazione'], 'bandi', 'gazzetta: l avviso pubblico deve andare ai bandi');
