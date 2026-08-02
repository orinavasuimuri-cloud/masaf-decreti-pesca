<?php
declare(strict_types=1);

/**
 * Lettura degli allegati di una pagina MASAF.
 *
 * Una pagina "IDPagina" non ospita un documento ma un contenitore: accanto al
 * decreto principale possono comparire allegati (elenchi di unita' autorizzate,
 * note esplicative, manuali) e decreti successivi che integrano o modificano il
 * primo. data/catalog.json ha storicamente registrato un solo PDF per voce,
 * quindi tutto cio' che una pagina aggiunge dopo la curatela resta invisibile
 * al sito: queste funzioni servono a rendere quella divergenza misurabile.
 *
 * Qui dentro solo parsing e confronto, niente rete: check_allegati.php fa le
 * richieste, questo file resta verificabile su fixture.
 */

/**
 * Estrae gli allegati scaricabili da una pagina MASAF.
 *
 * Nel markup del CMS ogni allegato e' un <a href="...ServeAttachment...">, il
 * cui testo e' il nome del documento seguito da uno <span class="BLOBDownloadSize">
 * con il peso fra parentesi. Il peso si legge dallo span e non dall'attributo
 * title perche' il title concatena nome e peso con una spaziatura variabile.
 *
 * Il redattore descrive ogni documento in un <div class="viewPar"> messo subito
 * prima del blocco di download. Senza quel testo il nome dell'allegato dice
 * solo "Allegato 1" o un numero di protocollo, e chi scarica non sa cosa sta
 * prendendo: viene raccolto come "testo". Vale solo il fratello immediatamente
 * precedente, altrimenti due download consecutivi erediterebbero entrambi la
 * descrizione del primo.
 *
 * @return array<string, array{titolo:string, peso:string, testo:string}> indicizzato
 *         per URL, cosi' i duplicati della stessa pagina collassano su una voce sola.
 */
function allegati_parse(string $html): array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    // Come in scraper.php: senza mbstring il prefisso XML e' l'unico modo
    // affidabile per imporre UTF-8 a prescindere da come il server lo dichiara.
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $out = [];

    foreach ($xpath->query('//a[contains(@href, "ServeAttachment")]') as $a) {
        if (!$a instanceof DOMElement) {
            continue;
        }
        $url = trim(html_entity_decode($a->getAttribute('href'), ENT_QUOTES, 'UTF-8'));
        if ($url === '') {
            continue;
        }

        $peso = '';
        $testo = $a->textContent;
        foreach ($xpath->query('.//span[contains(@class, "BLOBDownloadSize")]', $a) as $span) {
            $peso = trim($span->textContent, " \t\n\r\0\x0B()\u{00A0}");
            // Il peso fa parte del testo del link: va tolto per isolare il nome.
            $testo = str_replace($span->textContent, '', $testo);
        }

        $titolo = trim(preg_replace('/\s+/u', ' ', $testo) ?? '');
        // Alcune pagine ripetono lo stesso allegato in piu' punti del layout:
        // la prima resa e' quella con il nome, le successive a volte no.
        if (isset($out[$url]) && $titolo === '') {
            continue;
        }
        $out[$url] = [
            'titolo' => $titolo,
            'peso'   => $peso,
            'testo'  => allegati_descrizione($xpath, $a),
        ];
    }

    return $out;
}

/**
 * Testo descrittivo che il redattore ha messo prima del blocco di download.
 *
 * Il <br> separa concetti diversi (protocollo, oggetto, estremi di
 * registrazione) e va reso come spazio, altrimenti le parole ai due lati
 * finiscono attaccate.
 */
function allegati_descrizione(DOMXPath $xpath, DOMElement $link): string
{
    $blocchi = $xpath->query('ancestor::div[contains(@class, "blob-element-download")][1]', $link);
    $blocco = $blocchi === false ? null : $blocchi->item(0);
    if ($blocco === null) {
        return '';
    }

    // Fra il testo e il download il CMS infila div di sola spaziatura
    // (viewLineBreak): vanno scavalcati. Ci si ferma pero' al primo elemento
    // che porta contenuto: se e' un altro blocco di download, questo allegato
    // non ha una descrizione propria e non deve ereditare quella del vicino.
    $precedenti = $xpath->query(
        'preceding-sibling::*[not(contains(@class, "viewLineBreak"))][1][contains(@class, "viewPar")]',
        $blocco
    );
    $par = $precedenti === false ? null : $precedenti->item(0);
    if (!$par instanceof DOMElement) {
        return '';
    }

    // I <br> spariscono da textContent: vanno sostituiti prima di leggerlo.
    foreach (iterator_to_array($par->getElementsByTagName('br')) as $br) {
        $br->parentNode?->replaceChild($par->ownerDocument->createTextNode(' '), $br);
    }

    $testo = str_replace("\u{00A0}", ' ', $par->textContent);
    return trim(preg_replace('/\s+/u', ' ', $testo) ?? '');
}

/**
 * Converte il peso dichiarato dal CMS ("354.45 KB") nella forma usata dal
 * catalogo ("354,45 KB"). Il separatore decimale e' l'unica differenza.
 */
function allegati_peso_it(string $peso): string
{
    return preg_replace('/(\d)\.(\d)/', '$1,$2', trim($peso)) ?? trim($peso);
}

/**
 * Confronta gli allegati di una pagina con quelli gia' registrati.
 *
 * $esclusi elenca gli URL che non vanno registrati per scelta, con il motivo
 * come valore. Serve ai casi in cui il CMS espone lo stesso documento sotto
 * due handle diversi: senza, il controllo riproporrebbe all'infinito un file
 * che e' gia' in catalogo con un altro indirizzo.
 *
 * @param array<string, array{titolo:string, peso:string}> $inPagina
 * @param list<string> $registrati URL dei PDF presenti in catalog.json
 * @param array<string, string> $esclusi URL => motivo dell'esclusione
 * @return array{mancanti: array<string, array{titolo:string, peso:string}>, ignoti: list<string>, esclusi: list<string>}
 */
function allegati_confronta(array $inPagina, array $registrati, array $esclusi = []): array
{
    $noti = array_fill_keys($registrati, true);
    $mancanti = array_diff_key($inPagina, $noti);

    return [
        // Sulla pagina ma non in catalogo: da valutare per l'inserimento.
        'mancanti' => array_diff_key($mancanti, $esclusi),
        // In catalogo ma non piu' sulla pagina: link potenzialmente morto,
        // oppure documento sostituito da una versione a URL diverso.
        'ignoti'   => array_values(array_diff($registrati, array_keys($inPagina))),
        // Scartati per scelta, elencati per poterli contare nel riepilogo.
        'esclusi'  => array_values(array_intersect(array_keys($mancanti), array_keys($esclusi))),
    ];
}

/**
 * Raggruppa le voci del catalogo per pagina MASAF di provenienza.
 *
 * @return array<int, array{sezione:string, voci:list<array>, pdf:list<string>}>
 */
function allegati_pagine_catalogo(array $catalog): array
{
    $out = [];
    foreach ($catalog['sections'] ?? [] as $sezione) {
        foreach ($sezione['items'] ?? [] as $voce) {
            if (empty($voce['masaf_id'])) {
                continue;
            }
            $id = (int) $voce['masaf_id'];
            $out[$id]['sezione'] = $sezione['title'] ?? '';
            $out[$id]['voci'][] = $voce;
            if (!empty($voce['pdf'])) {
                $out[$id]['pdf'][] = $voce['pdf'];
            }
            // Gli allegati agganciati a una voce contano come registrati:
            // altrimenti il controllo li segnalerebbe come mancanti per sempre.
            foreach ($voce['allegati'] ?? [] as $all) {
                if (!empty($all['pdf'])) {
                    $out[$id]['pdf'][] = $all['pdf'];
                }
            }
        }
    }
    foreach ($out as $id => $_) {
        $out[$id]['pdf'] = array_values(array_unique($out[$id]['pdf'] ?? []));
    }
    ksort($out);
    return $out;
}
