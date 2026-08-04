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
