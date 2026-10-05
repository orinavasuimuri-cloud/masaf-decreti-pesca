<?php
declare(strict_types=1);

require_once __DIR__ . '/news_normalize.php';
require_once __DIR__ . '/keywords.php';

/**
 * Costruisce una voce normalizzata. Unico punto in cui si definisce la forma di
 * un item: parser diversi producono strutture identiche.
 */
function news_make_item(string $sourceId, string $title, string $url, string $rawDate, string $rawSummary, string $nowIso): array {
    return [
        'id'      => news_item_id($url),
        'source'  => $sourceId,
        'title'   => $title,
        'url'     => news_normalize_url($url),
        'date'    => news_parse_date($rawDate, $nowIso),
        'summary' => $rawSummary,
    ];
}

/**
 * @param list<string> $keywords Vuoto per fonti già in tema; valorizzato per le
 *     fonti generaliste (es. un'associazione che copre più settori oltre alla
 *     pesca), stesso filtro usato per le pagine MASAF.
 */
function news_parse_rss(string $xml, string $sourceId, string $nowIso, array $keywords = []): array {
    // Alcune fonti (regione.calabria.it) anticipano la dichiarazione XML con un
    // byte di whitespace (spesso un semplice "\n" prima di "<?xml"). Una
    // dichiarazione non in colonna 0 rende il documento non ben formato e
    // simplexml_load_string() restituisce false: senza trim() quella fonte
    // risulterebbe rotta per sempre, non solo occasionalmente.
    $xml = trim($xml);
    $prev = libxml_use_internal_errors(true);
    $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if ($sx === false || !isset($sx->channel->item)) {
        throw new RuntimeException('RSS non valido o senza item');
    }

    $pattern = keywords_pattern($keywords);

    $items = [];
    foreach ($sx->channel->item as $node) {
        $url = trim((string) $node->link);
        $title = news_to_utf8(trim((string) $node->title));
        if ($url === '' || $title === '') {
            continue;
        }
        if (!keywords_corrisponde($pattern, $title)) {
            continue;
        }
        $items[news_item_id($url)] = news_make_item(
            $sourceId,
            $title,
            $url,
            trim((string) $node->pubDate),
            news_clean_summary(news_to_utf8((string) $node->description)),
            $nowIso
        );
    }
    return array_values($items);
}

/**
 * Le notizie MASAF hanno URL "puliti" (masaf.gov.it/<slug>), distinti dai link di
 * navigazione che passano tutti da /flex/. La data non sta nel testo del link:
 * si cerca un dd/mm/yyyy risalendo fino a tre antenati, con fallback su $nowIso.
 */
function news_parse_masaf(string $html, string $sourceId, array $keywords, string $nowIso): array {
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    // Le pagine sono ISO-8859-1: si converte prima di dare il markup a DOMDocument.
    $dom->loadHTML(
        '<?xml encoding="UTF-8">' . news_to_utf8($html, 'ISO-8859-1'),
        LIBXML_NOWARNING | LIBXML_NOERROR
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query(
        '//a[starts-with(@href, "https://www.masaf.gov.it/")][not(contains(@href, "/flex/"))]'
    );

    if ($nodes->length === 0) {
        throw new RuntimeException('Nessun link di notizia trovato: struttura pagina MASAF cambiata o contenuto diverso');
    }

    // Costruito e validato una volta prima del ciclo: vedi lib/keywords.php,
    // dove sta anche il motivo per cui un'espressione rotta ferma la raccolta
    // invece di farla sembrare povera di notizie.
    $pattern = keywords_pattern($keywords);

    $items = [];
    foreach ($nodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $title = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
        // Sotto i 25 caratteri sono voci di menu e non titoli di notizia.
        if (strlen($title) < 25) {
            continue;
        }
        if (!keywords_corrisponde($pattern, $title)) {
            continue;
        }

        $rawDate = '';
        $ancestor = $node->parentNode;
        for ($i = 0; $i < 3 && $ancestor instanceof DOMElement; $i++) {
            if (preg_match('#\b(\d{2}/\d{2}/\d{4})\b#', $ancestor->textContent, $m) === 1) {
                $rawDate = $m[1];
                break;
            }
            $ancestor = $ancestor->parentNode;
        }
        // Il titolo può cominciare con la data: la si toglie dal testo mostrato.
        $title = trim((string) preg_replace('#^\d{2}/\d{2}/\d{4}\s*#', '', $title));

        $url = $node->getAttribute('href');
        $items[news_item_id($url)] = news_make_item($sourceId, $title, $url, $rawDate, '', $nowIso);
    }
    return array_values($items);
}
