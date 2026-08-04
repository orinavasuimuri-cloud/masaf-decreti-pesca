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
