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

/**
 * Cerca in un testo una qualunque delle parole date, senza distinguere
 * maiuscole, accenti compresi.
 *
 * Il delimitatore va passato a preg_quote: senza, una parola che contenga `/`
 * — e le parole arrivano dalla configurazione, non dal codice — produrrebbe
 * un'espressione malformata. Che e' anche il motivo del lancio: con
 * un'espressione rotta preg_match restituisce false, indistinguibile da
 * "nessuna corrispondenza", e ogni atto verrebbe scartato in silenzio. Una
 * fonte che smette di trovare qualsiasi cosa deve fermarsi con un errore, non
 * sembrare semplicemente povera di notizie.
 */
function gazzetta_corrisponde(string $testo, array $parole): bool
{
    $pattern = '/' . implode('|', array_map(
        static fn(string $p): string => preg_quote($p, '/'),
        $parole
    )) . '/iu';

    $esito = @preg_match($pattern, $testo);
    if ($esito === false) {
        throw new RuntimeException("Espressione di ricerca non valida: $pattern");
    }
    return $esito === 1;
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
