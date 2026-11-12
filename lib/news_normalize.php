<?php
declare(strict_types=1);

/**
 * Funzioni pure di normalizzazione. Nessun accesso a rete o disco: sono la base
 * testabile su cui poggiano parser, store e fetcher.
 */

/**
 * Vero solo per URL http(s) assoluti. Va chiesto prima di stampare come href un
 * link preso da un sito esterno (feed di notizie e di bandi): un sito
 * compromesso potrebbe mandare javascript:/data:, che diventerebbe un link
 * cliccabile capace di agire con la sessione di chi lo apre.
 */
function news_safe_url(?string $url): bool {
    // Schema insensibile alle maiuscole (RFC 3986); per il resto fail-closed:
    // niente spazi iniziali, schemi diversi o URL //host senza schema.
    return $url !== null && (stripos($url, 'http://') === 0 || stripos($url, 'https://') === 0);
}

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
    // Residuo di un plugin WordPress di ripubblicazione (agripesca.it): avvolge
    // il testo copiato in [[{"value":" ... "}]].
    $text = str_replace(['[[{"value":"', '"}]]'], ' ', $text);
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
        // Una data impossibile (12/25/2026 all'americana, 31/02) non deve
        // lanciare: farebbe saltare l'intera fonte, non solo questa voce.
        if (!checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return $fallbackIso;
        }
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
