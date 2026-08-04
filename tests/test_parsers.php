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

// --- RSS con whitespace prima della dichiarazione XML (regressione feed Calabria) ---
// La risposta reale di regione.calabria.it/feed/ comincia con un byte 0x0A prima di
// "<?xml": senza trim() il documento non è ben formato e simplexml_load_string()
// restituisce false, degradando la fonte a "rotta" per sempre.
$xmlConSpazio = file_get_contents(__DIR__ . '/fixtures/rss_leading_whitespace.xml');
// Non fissiamo QUALE whitespace (LF, CRLF, ...): dipende dai fine-riga con cui
// git materializza la fixture sul filesystem, non dal comportamento da testare.
// Cio' che conta e' che ci sia whitespace prima di "<?xml": se la fixture
// venisse "ripulita" per errore il test deve comunque fallire.
t_true(preg_match('/^\s/', $xmlConSpazio) === 1,
    'fixture: comincia davvero con whitespace prima di <?xml');
$itemsConSpazio = news_parse_rss($xmlConSpazio, 'calabria-rss', $now);
t_eq(count($itemsConSpazio), 1, 'RSS: whitespace iniziale non impedisce il parsing');
t_eq($itemsConSpazio[0]['title'], 'Avviso pubblico FEAMPA - bando pesca costiera',
    'RSS: titolo estratto correttamente nonostante il whitespace iniziale');

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

// Una parola chiave con la barra dentro non deve invalidare l'espressione: se
// non fosse protetta, il filtro scarterebbe tutto senza dire niente.
$conBarra = news_parse_masaf($html, 'masaf-notizie', array_merge($kw, ['acqua/mare']), $now);
t_eq(count($conBarra), count($filtered), 'MASAF: una parola chiave col delimitatore non deve svuotare il filtro');

// E se l'espressione non compila davvero, ci si ferma: restituire zero voci la
// confonderebbe con una pagina senza notizie in tema.
$kwRotte = false;
try { news_parse_masaf($html, 'masaf-notizie', ["\xC3\x28"], $now); } catch (RuntimeException) { $kwRotte = true; }
t_true($kwRotte, 'MASAF: parole chiave non compilabili devono lanciare RuntimeException');

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

// --- MASAF HTML invalido ---
$caught = false;
try { news_parse_masaf('<<<non html', 'masaf-notizie', [], $now); } catch (RuntimeException) { $caught = true; }
t_true($caught, 'MASAF: HTML senza link di notizia solleva RuntimeException');

// --- MASAF filtro vuoto non è un errore ---
$caught = false;
try {
    $filtered_empty = news_parse_masaf($html, 'masaf-notizie', ['xxxxnonesistexxxx'], $now);
    t_eq($filtered_empty, [], 'MASAF: filtro inesistente restituisce array vuoto');
} catch (RuntimeException) { $caught = true; }
t_true(!$caught, 'MASAF: filtro vuoto su fixture valida non solleva eccezione');
