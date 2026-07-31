<?php
declare(strict_types=1);

/**
 * Funzioni pure di normalizzazione. Nessun accesso a rete o disco: sono la base
 * testabile su cui poggiano parser, store e fetcher.
 */

function news_normalize_url(string $url): string {
    $url = trim($url);
    [$url] = explode('#', $url, 2);
    [$base, $query] = array_pad(explode('?', $url, 2), 2, '');
    if ($query === '') {
        return $base;
    }
    parse_str($query, $params);
    $kept = array_filter(
        $params,
        static fn(string $k): bool => !str_starts_with(strtolower($k), 'utm_'),
        ARRAY_FILTER_USE_KEY
    );
    return $kept === [] ? $base : $base . '?' . http_build_query($kept);
}

function news_item_id(string $url): string {
    return sha1(news_normalize_url($url));
}

/**
 * Porta la stringa in UTF-8 valido. Senza mbstring si usa iconv; //IGNORE scarta
 * i byte non convertibili. Serve perché json_encode restituisce false su UTF-8
 * non valido, e un salvataggio non verificato produrrebbe un file vuoto.
 */
function news_to_utf8(string $s, string $from = 'UTF-8'): string {
    if (strtoupper($from) !== 'UTF-8') {
        $converted = @iconv($from, 'UTF-8//IGNORE', $s);
        if ($converted !== false) {
            $s = $converted;
        }
    }
    $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
    return $clean === false ? '' : $clean;
}

function news_clean_summary(string $html, int $max = 200): string {
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    if ($text === '') {
        return '';
    }
    if (preg_match('/^.{0,' . $max . '}(?=\s|$)/u', $text, $m) === 1 && $m[0] !== $text) {
        return rtrim($m[0], " ,.;:") . '…';
    }
    return $text;
}

/**
 * Restituisce una data ISO 8601. Gli RSS usano RFC-2822, il MASAF dd/mm/yyyy.
 * Una data non interpretabile ricade sul fallback (di norma l'istante di primo
 * rilevamento) invece di far sparire la voce o mandarla in cima all'elenco.
 */
function news_parse_date(string $raw, string $fallbackIso): string {
    $raw = trim($raw);
    if ($raw === '') {
        return $fallbackIso;
    }
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $raw, $m) === 1) {
        return (new DateTimeImmutable(
            "{$m[3]}-{$m[2]}-{$m[1]} 00:00:00",
            new DateTimeZone('Europe/Rome')
        ))->format('c');
    }
    try {
        return (new DateTimeImmutable($raw))->format('c');
    } catch (Throwable) {
        return $fallbackIso;
    }
}

/**
 * Formatta una data, restituendo $fallback se è assente o non interpretabile.
 * Con strict_types=1 strtotime() che fallisce restituisce false e date() lo
 * rifiuta con un TypeError fatale: la guardia evita che una singola data
 * malformata mandi in bianco l'intera pagina. Condivisa da news.php e
 * bandi.php: entrambe rendono date che arrivano da fonti esterne, quindi non
 * garantite valide.
 */
function news_date_label(?string $date, string $fmt = 'd/m/Y', string $fallback = '-'): string {
    if (!$date) {
        return $fallback;
    }
    $ts = strtotime($date);
    return $ts === false ? $fallback : date($fmt, $ts);
}
