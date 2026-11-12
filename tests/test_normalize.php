<?php
require_once __DIR__ . '/../lib/news_normalize.php';

// --- news_normalize_url ---
t_eq(news_normalize_url('  https://x.it/a  '), 'https://x.it/a', 'spazi rimossi');
t_eq(news_normalize_url('https://x.it/a#sec'), 'https://x.it/a', 'fragment rimosso');
t_eq(news_normalize_url('https://x.it/a?utm_source=fb&id=3'), 'https://x.it/a?id=3', 'utm_ scartati');
t_eq(news_normalize_url('https://x.it/a?utm_source=fb'), 'https://x.it/a', 'query vuota niente ?');
t_eq(news_normalize_url('https://x.it/a?id=3'), 'https://x.it/a?id=3', 'query utile conservata');

// --- news_safe_url: i link presi da siti esterni (notizie, bandi, striscia) ---
t_true(news_safe_url('https://x.it/a'), 'safe_url: https accettato');
t_true(news_safe_url('HTTP://x.it/a'), 'safe_url: schema maiuscolo accettato');
t_true(!news_safe_url('javascript:alert(1)'), 'safe_url: javascript: rifiutato');
t_true(!news_safe_url(' https://x.it'), 'safe_url: spazio iniziale rifiutato');
t_true(!news_safe_url('//x.it/a'), 'safe_url: senza schema rifiutato');
t_true(!news_safe_url(null), 'safe_url: null rifiutato');

// --- news_item_id ---
t_eq(
    news_item_id('https://x.it/a?utm_source=fb'),
    news_item_id('https://x.it/a'),
    'stesso articolo, stesso id nonostante utm'
);
t_true(news_item_id('https://x.it/a') !== news_item_id('https://x.it/b'), 'url diversi, id diversi');
t_eq(strlen(news_item_id('https://x.it/a')), 40, 'id sha1 lungo 40');

// --- news_to_utf8 ---
t_eq(news_to_utf8("attivit\xE0 di pesca", 'ISO-8859-1'), 'attività di pesca', 'latin1 convertito');
t_eq(news_to_utf8('attività di pesca'), 'attività di pesca', 'utf8 invariato');
t_true(json_encode(['t' => news_to_utf8("rotto \xFF\xFE qui")]) !== false, 'byte invalidi resi codificabili');

// --- news_clean_summary ---
t_eq(news_clean_summary('<p>Ciao   <b>mondo</b></p>'), 'Ciao mondo', 'tag via, spazi compattati');
t_eq(news_clean_summary('Pesca &amp; acquacoltura'), 'Pesca & acquacoltura', 'entita decodificate');
$long = str_repeat('parola ', 60);
$cut = news_clean_summary($long, 50);
t_true(strlen($cut) <= 54, 'troncato entro il limite');
t_true(str_ends_with($cut, '…'), 'ellissi finale');
t_eq(news_clean_summary('Attività à è ò', 200), 'Attività à è ò', 'accenti integri');
t_eq(
    news_clean_summary('<p> [[{"value":"</p><p><em>UILA Pesca chiede criteri sociali.</em></p><p>"}]]</p>'),
    'UILA Pesca chiede criteri sociali.',
    'residuo del plugin di ripubblicazione rimosso'
);

// --- news_parse_date ---
$fb = '2026-01-01T00:00:00+01:00';
t_eq(substr(news_parse_date('Tue, 28 Jul 2026 06:08:09 +0000', $fb), 0, 10), '2026-07-28', 'RFC-2822');
t_eq(substr(news_parse_date('28/07/2026', $fb), 0, 10), '2026-07-28', 'formato MASAF dd/mm/yyyy');
t_eq(news_parse_date('12/25/2026', $fb), $fb, 'data americana: fallback, non eccezione');
t_eq(news_parse_date('31/02/2026', $fb), $fb, '31 febbraio: fallback, non 3 marzo');
t_eq(news_parse_date('', $fb), $fb, 'stringa vuota usa il fallback');
t_eq(news_parse_date('non una data', $fb), $fb, 'spazzatura usa il fallback');
