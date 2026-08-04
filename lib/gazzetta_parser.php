<?php
declare(strict_types=1);

/**
 * Lettura dei sommari RSS della Gazzetta Ufficiale.
 *
 * Ogni feed della GU non e' uno storico interrogabile ma il sommario di un solo
 * fascicolo: quello uscito per ultimo. Numero e data del fascicolo stanno nella
 * <description> del canale, non negli item, e servono a riconoscere i numeri
 * saltati fra un'esecuzione e l'altra.
 *
 * Qui dentro solo parsing e classificazione, niente rete: gazzetta_fetcher.php
 * fa le richieste, questo file resta verificabile su fixture.
 */

/**
 * @return array{numero:int, data:string, items:list<array{titolo:string, oggetto:string, url:string}>}
 */
function gazzetta_parse_sommario(string $xml): array
{
    // Stesso trim di news_parse_rss(): una dichiarazione XML non in colonna 0
    // rende il documento non ben formato e il caricamento fallisce.
    $xml = trim($xml);
    $prev = libxml_use_internal_errors(true);
    $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if ($sx === false || !isset($sx->channel->item)) {
        throw new RuntimeException('Sommario GU non valido o senza item');
    }

    $descrizione = trim((string) $sx->channel->description);
    if (preg_match('/n\.\s*(\d+)\s+del\s+(\d{2})-(\d{2})-(\d{4})/u', $descrizione, $m) !== 1) {
        throw new RuntimeException("Numero del fascicolo non riconosciuto: $descrizione");
    }

    $items = [];
    foreach ($sx->channel->item as $node) {
        $titolo = trim((string) preg_replace('/\s+/u', ' ', (string) $node->title));
        $url = trim((string) $node->link);
        if ($titolo === '' || $url === '') {
            continue;
        }
        // content:encoded sta in un namespace: ->children() con l'URI e' l'unico
        // modo per raggiungerlo da SimpleXML.
        $contenuto = $node->children('http://purl.org/rss/1.0/modules/content/');
        $oggetto = trim((string) preg_replace('/\s+/u', ' ', (string) $contenuto->encoded));
        $items[] = ['titolo' => $titolo, 'oggetto' => $oggetto, 'url' => $url];
    }

    return [
        'numero' => (int) $m[1],
        'data'   => sprintf('%s-%s-%s', $m[4], $m[3], $m[2]),
        'items'  => $items,
    ];
}
