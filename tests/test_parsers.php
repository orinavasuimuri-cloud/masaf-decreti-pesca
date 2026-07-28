<?php
require_once __DIR__ . '/../lib/news_parsers.php';

$now = '2026-07-28T12:00:00+02:00';

// --- RSS ---
$xml = file_get_contents(__DIR__ . '/fixtures/rss_pesceinrete.xml');
$items = news_parse_rss($xml, 'pesceinrete', $now);
t_true(count($items) >= 5, 'RSS: almeno 5 voci estratte');
$first = $items[0];
t_eq($first['source'], 'pesceinrete', 'RSS: source valorizzato');
t_true($first['title'] !== '', 'RSS: titolo non vuoto');
t_true(str_starts_with($first['url'], 'http'), 'RSS: url assoluto');
t_eq(strlen($first['id']), 40, 'RSS: id sha1');
t_true(preg_match('/^\d{4}-\d{2}-\d{2}T/', $first['date']) === 1, 'RSS: data ISO 8601');
t_true(json_encode($items) !== false, 'RSS: risultato codificabile in JSON');
t_true(strip_tags($first['summary']) === $first['summary'], 'RSS: estratto senza tag');

$ids = array_column($items, 'id');
t_eq(count($ids), count(array_unique($ids)), 'RSS: nessun id duplicato');

// --- RSS non valido ---
$caught = false;
try { news_parse_rss('<<<non xml', 'x', $now); } catch (RuntimeException) { $caught = true; }
t_true($caught, 'RSS: input non valido solleva RuntimeException');

// --- MASAF ---
$html = file_get_contents(__DIR__ . '/fixtures/masaf_notizie.html');
$kw = ['pesca', 'ittic', 'acquacolt', 'tonno'];
$all = news_parse_masaf($html, 'masaf-notizie', [], $now);
t_true(count($all) >= 5, 'MASAF: senza filtro estrae le notizie');
t_true(
    !in_array(true, array_map(static fn($i) => str_contains($i['url'], '/flex/'), $all), true),
    'MASAF: esclusi i link di navigazione /flex/'
);

$filtered = news_parse_masaf($html, 'masaf-notizie', $kw, $now);
t_true(count($filtered) <= count($all), 'MASAF: il filtro non aggiunge voci');

if ($filtered === []) {
    // La pagina MASAF può legittimamente non avere notizie di pesca. Si verifica che il
    // vuoto derivi dall'assenza di parole chiave nei titoli, non da un parser che ha
    // smesso di estrarre qualsiasi cosa.
    t_true($all !== [], 'MASAF: filtro vuoto ma il parser estrae comunque notizie');
    $conKeyword = array_filter(
        $all,
        static fn(array $i): bool => (bool) preg_match('/pesca|ittic|acquacolt|tonno/iu', $i['title'])
    );
    t_eq($conKeyword, [], 'MASAF: filtro vuoto perché nessun titolo contiene le parole chiave');
} else {
    foreach ($filtered as $i) {
        t_true(
            (bool) preg_match('/pesca|ittic|acquacolt|tonno/iu', $i['title']),
            'MASAF: ogni voce filtrata contiene una parola chiave'
        );
    }
}
t_true(json_encode($filtered) !== false, 'MASAF: risultato codificabile (charset ISO-8859-1)');
