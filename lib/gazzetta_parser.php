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

require_once __DIR__ . '/keywords.php';

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
 * Carica HTML in un DOMDocument senza riempire il log di libxml.
 *
 * Il prologo XML davanti al documento non e' un vezzo: loadHTML() senza
 * dichiarazione di codifica assume ISO-8859-1 e storpierebbe ogni accento dei
 * titoli, e mb_convert_encoding() con 'HTML-ENTITIES', il rimedio di una volta,
 * e' deprecato da PHP 8.2. Vale anche per questo progetto, dove mbstring non e'
 * detto sia caricata.
 */
function gazzetta_dom(string $html): DOMDocument
{
    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $dom;
}

/** Il template GU spezza i testi su piu' righe con i tab: qui non ci passano. */
function gazzetta_normalizza_testo(string $testo): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $testo));
}

/**
 * Numero e data di ogni fascicolo dell'anno, letti dall'archivio completo.
 *
 * Serve a recuperare un fascicolo saltato: il feed RSS pubblica solo l'ultimo
 * uscito, e l'indirizzo di un fascicolo passato si costruisce con la sua data,
 * che dal solo numero non si ricava. Dedurla contando i giorni feriali
 * all'indietro darebbe la risposta giusta quasi sempre, ed e' proprio il quasi
 * a renderla inutilizzabile: la GU salta le domeniche ma anche le feste, e un
 * fascicolo attribuito al giorno sbagliato verrebbe archiviato con una data
 * falsa. Qui la corrispondenza la dichiara la fonte.
 *
 * @return array<int, string> numero del fascicolo => data in formato Y-m-d
 */
function gazzetta_parse_archivio_anno(string $html): array
{
    $xpath = new DOMXPath(gazzetta_dom($html));
    $fascicoli = [];

    // Si filtra sul percorso del dettaglio, non sulle classi CSS: il primo e'
    // il contratto verso cui i link puntano, le seconde sono presentazione.
    foreach ($xpath->query('//a[contains(@href, "/gazzetta/serie_generale/caricaDettaglio")]') as $link) {
        /** @var DOMElement $link */
        $href = html_entity_decode($link->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $query = parse_url($href, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            continue;
        }
        // parse_str invece di una regex sui due parametri: cosi' l'ordine in cui
        // compaiono nella query non conta, e nemmeno che l'ampersand sia scritto
        // come & o come &amp;.
        parse_str($query, $params);

        $numero = $params['numeroGazzetta'] ?? null;
        $data   = $params['dataPubblicazioneGazzetta'] ?? null;
        if (!is_string($numero) || !is_string($data)) {
            continue;
        }
        if (!ctype_digit($numero) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) !== 1) {
            continue;
        }
        $fascicoli[(int) $numero] = $data;
    }

    if ($fascicoli === []) {
        throw new RuntimeException('Archivio annuale GU senza fascicoli riconoscibili');
    }
    return $fascicoli;
}

/**
 * Come gazzetta_parse_sommario(), ma dalla pagina HTML di un fascicolo invece
 * che dal feed: stessa struttura in uscita, cosi' il resto del giro - voci,
 * merge, travaso ai bandi - non sa da dove arriva il sommario.
 *
 * Il fascicolo dichiara numero e data nella propria intestazione, e si leggono
 * da li' anziche' fidarsi di quelli chiesti nell'indirizzo: se la GU
 * rispondesse con un fascicolo diverso da quello domandato, chi chiama deve
 * potersene accorgere confrontando.
 *
 * @return array{numero:int, data:string, items:list<array{titolo:string, oggetto:string, url:string}>}
 */
function gazzetta_parse_sommario_html(string $html): array
{
    $xpath = new DOMXPath(gazzetta_dom($html));

    $intestazione = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " intestazione ")]')->item(0);
    $testo = $intestazione !== null ? gazzetta_normalizza_testo($intestazione->textContent) : '';

    // Nell'intestazione giorno e mese non hanno lo zero davanti ("del 6-8-2026"),
    // al contrario della <description> del feed: la regex accetta una cifra sola
    // e il riempimento lo fa sprintf.
    if (preg_match('/n\.\s*(\d+)\s+del\s+(\d{1,2})-(\d{1,2})-(\d{4})/u', $testo, $m) !== 1) {
        throw new RuntimeException('Intestazione del fascicolo GU non riconosciuta: ' . substr($testo, 0, 120));
    }
    // Una data che non esiste sul calendario passerebbe la regex e finirebbe in
    // archivio come data dell'atto: qui si ferma, perche' una voce datata 31
    // febbraio non e' meno sbagliata di una voce mancante, e' solo piu' difficile
    // da notare.
    if (!checkdate((int) $m[3], (int) $m[2], (int) $m[4])) {
        throw new RuntimeException("Data del fascicolo GU inesistente: {$m[2]}-{$m[3]}-{$m[4]}");
    }

    // Rubrica ed emettitore valgono per gli atti che seguono, fino alla
    // prossima occorrenza: l'unione XPath restituisce i nodi nell'ordine del
    // documento, ed e' quell'ordine a ricostruire l'appartenenza senza dover
    // risalire la gerarchia del template.
    $nodi = $xpath->query(
        '//span[contains(concat(" ", normalize-space(@class), " "), " rubrica ")]'
        . ' | //span[contains(concat(" ", normalize-space(@class), " "), " emettitore ")]'
        . ' | //a[contains(@href, "caricaDettaglioAtto")]'
    );

    $emittente = '';
    $atti = [];

    foreach ($nodi as $nodo) {
        /** @var DOMElement $nodo */
        if ($nodo->tagName === 'span') {
            $classe = $nodo->getAttribute('class');
            if (str_contains($classe, 'rubrica')) {
                // Cambiando rubrica l'emittente decade. Nei fascicoli visti
                // finora ogni rubrica apre col proprio, e il reset non cambia
                // nulla; serve per quelle che non ne hanno - "LEGGI ED ALTRI
                // ATTI NORMATIVI" - che altrimenti si prenderebbero il
                // ministero della rubrica precedente.
                $emittente = '';
            } elseif (str_contains($classe, 'emettitore')) {
                $emittente = gazzetta_normalizza_testo($nodo->textContent);
            }
            continue;
        }

        // Ogni atto ha due <a> di seguito con lo stesso indirizzo: il primo
        // porta il tipo dentro span.data, il secondo l'oggetto. L'indirizzo e'
        // quindi la chiave con cui rimetterli insieme senza contarli a coppie.
        $href = html_entity_decode($nodo->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!isset($atti[$href])) {
            $atti[$href] = ['tipo' => null, 'oggetto' => null, 'emittente' => $emittente];
        }

        $spanTipo = $xpath->query('.//span[contains(concat(" ", normalize-space(@class), " "), " data ")]', $nodo)->item(0);
        if ($spanTipo !== null) {
            $atti[$href]['tipo'] = gazzetta_normalizza_testo($spanTipo->textContent);
            continue;
        }

        // Dell'oggetto si prendono i soli nodi di testo diretti: span.pagina
        // ("Pag. 12") e span.riferimento sono figli dello stesso <a> e
        // finirebbero dentro la descrizione dell'atto.
        $pezzi = [];
        foreach ($nodo->childNodes as $figlio) {
            if ($figlio instanceof DOMText) {
                $pezzi[] = $figlio->textContent;
            }
        }
        $atti[$href]['oggetto'] = gazzetta_normalizza_testo(implode(' ', $pezzi));
    }

    $items = [];
    foreach ($atti as $href => $atto) {
        // Un atto a cui manca meta' della coppia e' markup degradato: si salta
        // invece di emettere una voce monca, che a valle passerebbe per buona.
        if ($atto['tipo'] === null || $atto['oggetto'] === null) {
            continue;
        }
        // Stessa forma del titolo che arriva dal feed, "<EMITTENTE> - <TIPO>",
        // perche' gazzetta_scompone_titolo() la separa sull'ultimo ' - '.
        $items[] = [
            'titolo'  => $atto['emittente'] !== '' ? $atto['emittente'] . ' - ' . $atto['tipo'] : $atto['tipo'],
            'oggetto' => $atto['oggetto'],
            'url'     => str_starts_with($href, '/') ? 'https://www.gazzettaufficiale.it' . $href : $href,
        ];
    }

    // Un fascicolo senza nemmeno un atto riconosciuto non e' un fascicolo vuoto:
    // la Gazzetta non ne pubblica. E' il template della lista che e' cambiato
    // mentre l'intestazione e' rimasta leggibile. Distinguerlo conta perche' chi
    // chiama, non vedendo errori, darebbe il fascicolo per recuperato e lo
    // toglierebbe dai saltati: il buco resterebbe aperto e nessuno lo saprebbe
    // piu'. Meglio fallire e riprovarci al giro dopo.
    if ($items === []) {
        throw new RuntimeException("Fascicolo GU {$m[1]}: intestazione leggibile ma nessun atto riconosciuto");
    }

    return [
        'numero' => (int) $m[1],
        'data'   => sprintf('%04d-%02d-%02d', (int) $m[4], (int) $m[3], (int) $m[2]),
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

/**
 * Cerca in un testo una qualunque delle parole date, senza distinguere
 * maiuscole, accenti compresi.
 *
 * Resta come funzione a se' perche' e' il verbo con cui il resto di questo
 * file ragiona, ma il filtro vero sta in lib/keywords.php, condiviso con le
 * altre due fonti: vedi li' perche' un'espressione rotta deve lanciare invece
 * di restituire "nessuna corrispondenza".
 *
 * A differenza degli altri due chiamanti, qui il pattern si ricostruisce a ogni
 * atto invece che una volta prima del ciclo. Il sommario di un fascicolo conta
 * poche decine di voci e la differenza non si misura; separare la costruzione
 * dal confronto anche qui vorrebbe dire cambiare la firma di gazzetta_in_tema()
 * e di chi la chiama, per un guadagno che non c'e'.
 */
function gazzetta_corrisponde(string $testo, array $parole): bool
{
    return keywords_corrisponde(keywords_pattern($parole), $testo);
}

/**
 * Un atto e' in tema se le parole chiave compaiono nell'oggetto o nel titolo.
 *
 * L'oggetto e' la parte che conta: nella GU il titolo dice chi ha firmato e che
 * tipo di atto e', non di cosa tratta ("MINISTERO DELL'AGRICOLTURA... - DECRETO
 * 15 luglio 2026"). Un filtro sul solo titolo, come quello usato per i feed
 * regionali, sulla GU non troverebbe nulla. Il titolo si guarda comunque perche'
 * altre serie ci mettono la materia.
 *
 * Filtrare per solo emittente non e' un'alternativa: il MASAF governa
 * agricoltura, foreste e pesca, e la pesca e' la minoranza dei suoi atti.
 */
function gazzetta_in_tema(string $titolo, string $oggetto, array $keywords): bool
{
    if ($keywords === []) {
        return true;
    }
    return gazzetta_corrisponde($oggetto . ' ' . $titolo, $keywords);
}

/**
 * Dove va a finire l'atto: pagina dei bandi o coda di revisione del registro.
 *
 * Il dubbio va al registro, non ai bandi: il registro ha un cancello umano - le
 * voci restano "da rivedere" finche' qualcuno non le guarda - mentre la pagina
 * dei bandi pubblica quello che riceve. Un decreto finito per errore fra i
 * bandi viene visto dai lettori; un bando finito per errore nella coda di
 * revisione viene visto dal curatore, che lo sposta.
 *
 * "contribut" non e' fra i segnali: e' frequente negli atti che individuano
 * beneficiari, che sono provvedimenti e non avvisi a cui ci si candida.
 */
function gazzetta_destinazione(string $oggetto): string
{
    $segnali = [
        'bando',
        'avviso pubblico',
        'graduatoria',
        'manifestazione di interesse',
        'domande di partecipazione',
    ];
    return gazzetta_corrisponde($oggetto, $segnali) ? 'bandi' : 'registro';
}

/**
 * Le voci in tema di un sommario, gia' classificate e pronte per l'archivio.
 *
 * Gli atti senza codice identificativo vengono scartati: senza un id stabile
 * ogni esecuzione li ripresenterebbe come nuovi e la coda di revisione non si
 * svuoterebbe mai.
 *
 * L'indicizzazione per codice ha un secondo effetto voluto: se lo stesso atto
 * compare due volte nel sommario, resta una voce sola.
 *
 * @param array{numero:int, data:string, items:list<array{titolo:string, oggetto:string, url:string}>} $sommario
 * @return list<array>
 */
function gazzetta_voci(array $sommario, string $serieId, array $keywords, string $oggi): array
{
    $voci = [];
    foreach ($sommario['items'] as $item) {
        if (!gazzetta_in_tema($item['titolo'], $item['oggetto'], $keywords)) {
            continue;
        }
        $codice = gazzetta_codice_atto($item['oggetto'], $item['url']);
        if ($codice === '') {
            continue;
        }
        $parti = gazzetta_scompone_titolo($item['titolo']);
        $voci[$codice] = [
            'id'           => $codice,
            'serie'        => $serieId,
            'emittente'    => $parti['emittente'],
            'tipo_atto'    => $parti['tipo_atto'],
            'titolo'       => $item['titolo'],
            'oggetto'      => $item['oggetto'],
            'url'          => $item['url'],
            'numero_gu'    => $sommario['numero'],
            'data_gu'      => $sommario['data'],
            'destinazione' => gazzetta_destinazione($item['oggetto']),
            'status'       => 'pending_review',
            'first_seen'   => $oggi,
            // Presente su tutte le voci, non solo su quelle dirette ai bandi:
            // due forme diverse per lo stesso oggetto costringerebbero ogni
            // lettore a sapere quale delle due ha in mano. Su una voce diretta
            // al registro resta false per sempre, ed e' la verita'.
            'travasato'    => false,
        ];
    }
    return array_values($voci);
}

/**
 * Le voci che devono comparire nel riquadro "da rivedere" del registro.
 *
 * Entrano solo quelle ancora da guardare e dirette al registro: le voci gia'
 * valutate dal curatore e quelle instradate ai bandi non appartengono a questa
 * coda. Ordinate dalla piu' recente, come la coda MASAF.
 *
 * E' una funzione e non un array_filter dentro la pagina perche' un controllo
 * sul solo markup ricaverebbe il numero atteso dallo stesso file che la pagina
 * legge: con l'archivio vuoto - il caso normale, la pesca compare di rado in
 * Gazzetta - asserirebbe 0 === 0 e non potrebbe fallire.
 *
 * @param array<string, array> $items
 * @return list<array>
 */
function gazzetta_da_rivedere(array $items): array
{
    $coda = array_values(array_filter(
        $items,
        static fn(array $v): bool => ($v['status'] ?? '') === 'pending_review'
            && ($v['destinazione'] ?? '') === 'registro'
    ));
    usort($coda, static fn(array $a, array $b): int => strcmp($b['data_gu'] ?? '', $a['data_gu'] ?? ''));
    return $coda;
}
