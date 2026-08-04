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

/**
 * Separa l'emittente dal tipo di atto.
 *
 * Il titolo GU ha la forma "<EMITTENTE> - <TIPO> <data>", ma l'emittente puo'
 * contenere a sua volta un " - " ("PRESIDENZA DEL CONSIGLIO DEI MINISTRI -
 * DIPARTIMENTO PER LA TRASFORMAZIONE DIGITALE - DECRETO 19 marzo 2026"): si
 * separa sull'ultimo, non sul primo, altrimenti l'atto verrebbe attribuito al
 * dipartimento anziche' alla presidenza.
 *
 * Quando il separatore manca (" DECRETO LEGISLATIVO 26 giugno 2026, n.138")
 * l'emittente e' vuoto: sono atti dello Stato, non di un ministero.
 *
 * @return array{emittente:string, tipo_atto:string}
 */
function gazzetta_scompone_titolo(string $titolo): array
{
    $titolo = trim($titolo);
    $pos = strrpos($titolo, ' - ');
    if ($pos === false) {
        $emittente = '';
        $coda = $titolo;
    } else {
        $emittente = trim(substr($titolo, 0, $pos));
        $coda = trim(substr($titolo, $pos + 3));
    }

    // Il tipo e' la sequenza di parole maiuscole in testa alla coda, fino alla
    // data o alla fine. Le lettere accentate maiuscole, l'apostrofo e il trattino
    // fanno parte dei nomi degli atti (DECRETO-LEGGE, TESTO COORDINATO DEL
    // DECRETO-LEGGE), la virgola no.
    // Apici singoli e \x{...}: la classe di caratteri va consegnata a PCRE cosi'
    // com'e'. Fra apici doppi PHP interpreterebbe \u{...} da se', prima che il
    // motore delle espressioni regolari veda alcunche'.
    $tipo = $coda;
    if (preg_match('/^([A-Z\x{00C0}-\x{00DE}\'\-\s]+?)(?=\s+\d|,|$)/u', $coda, $m) === 1) {
        $tipo = trim($m[1]);
    }

    return ['emittente' => $emittente, 'tipo_atto' => $tipo];
}

/**
 * Identificatore stabile dell'atto, assegnato dalla fonte.
 *
 * Compare in coda all'oggetto fra parentesi e dentro l'URL ELI. Si preferisce
 * l'oggetto perche' e' la forma canonica; l'URL e' la riserva. Restituisce
 * stringa vuota quando non e' ricavabile da nessuna delle due: senza un
 * identificatore stabile ogni esecuzione ripresenterebbe la voce come nuova,
 * quindi chi chiama deve scartarla anziche' inventarne uno.
 */
function gazzetta_codice_atto(string $oggetto, string $url): string
{
    if (preg_match('/\(([0-9]{2}[A-Z][0-9]{5})\)\s*$/u', trim($oggetto), $m) === 1) {
        return $m[1];
    }
    if (preg_match('#/eli/id/\d{4}/\d{2}/\d{2}/([0-9]{2}[A-Z][0-9]{5})/#', $url, $m) === 1) {
        return $m[1];
    }
    return '';
}
