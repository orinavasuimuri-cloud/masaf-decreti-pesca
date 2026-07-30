<?php
declare(strict_types=1);

require_once __DIR__ . '/news_normalize.php';
require_once __DIR__ . '/bandi_normalize.php';

/**
 * Forma canonica di una voce. Unico punto in cui si definisce la struttura:
 * archivio e feed producono array identici, così store e pagina non devono
 * sapere da dove arriva il dato.
 */
function bandi_voce(array $campi): array {
    return [
        'id'                 => $campi['id'],
        'wp_id'              => $campi['wp_id'] ?? 0,
        'origine'            => $campi['origine'],
        'regioni'            => $campi['regioni'] ?? [],
        'priorita'           => $campi['priorita'] ?? '',
        'titolo'             => $campi['titolo'] ?? '',
        'scopo'              => $campi['scopo'] ?? '',
        'codice_intervento'  => $campi['codice_intervento'] ?? '',
        'pubblicazione'      => $campi['pubblicazione'] ?? null,
        'scadenza'           => $campi['scadenza'] ?? null,
        'terminato_in_fonte' => $campi['terminato_in_fonte'] ?? false,
        'nota'               => $campi['nota'] ?? '',
        'url_fonte'          => $campi['url_fonte'],
        'url_ufficiale'      => $campi['url_ufficiale'] ?? null,
        'dettagli_mancanti'  => $campi['dettagli_mancanti'] ?? false,
    ];
}

/**
 * Estrae le voci da una pagina /regione/<slug>/ dell'aggregatore.
 *
 * I campi non stanno in elementi con classi proprie: sono testo che segue
 * <strong>etichetta</strong> dentro un unico contenitore Divi. Si individua
 * quindi l'<article> con XPath e si leggono i campi con espressioni regolari
 * sul suo frammento HTML. Un campo mancante non fa saltare la voce: resta con
 * i dati minimi e dettagli_mancanti a true.
 */
function bandi_parse_archivio(string $html, string $fonteId = 'aggregatore'): array {
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $loaded = $dom->loadHTML(
        '<?xml encoding="UTF-8">' . news_to_utf8($html),
        LIBXML_NOWARNING | LIBXML_NOERROR
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if ($loaded === false) {
        throw new RuntimeException('HTML archivio non parsabile');
    }

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//article[contains(@class, "et_pb_post")]');
    if ($nodes === false || $nodes->length === 0) {
        throw new RuntimeException('Nessun articolo trovato: struttura archivio cambiata');
    }

    $voci = [];
    foreach ($nodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $frag = (string) $dom->saveHTML($node);
        $class = $node->getAttribute('class');

        $link = $xpath->query('.//h2[contains(@class, "entry-title")]//a', $node);
        if ($link === false || $link->length === 0) {
            continue; // senza permalink non esiste un id stabile
        }
        $anchor = $link->item(0);
        $permalink = trim($anchor->getAttribute('href'));
        if ($permalink === '') {
            continue;
        }
        $priorita = bandi_testo($anchor->textContent);

        $wpId = 0;
        if (preg_match('/^post-(\d+)$/', $node->getAttribute('id'), $m) === 1) {
            $wpId = (int) $m[1];
        }

        $scopo = '';
        if (preg_match('#Scopo\s+Contributo:?\s*</strong>(.*?)(?=<strong|<!--)#is', $frag, $m) === 1) {
            $scopo = news_clean_summary(bandi_testo($m[1]), 300);
        }

        $codice = '';
        if (preg_match('#Codice\s+di\s+intervento\s*</strong>\s*<p[^>]*>(.*?)</p>#is', $frag, $m) === 1) {
            $codice = bandi_testo($m[1]);
        }

        $pubblicazione = null;
        if (preg_match('#Data\s+di\s+pubblicazione:?\s*</strong>([^<]*)#i', $frag, $m) === 1) {
            $pubblicazione = bandi_parse_data_italiana($m[1]);
        }

        $scadenza = null;
        if (preg_match('#Data\s+di\s+scadenza:?\s*</strong>([^<]*)#i', $frag, $m) === 1) {
            $scadenza = bandi_parse_data_italiana($m[1]);
        }

        // Le proroghe stanno in coda alla scadenza, prima del blocco "Leggi tutto".
        $nota = '';
        if (preg_match('#Data\s+di\s+scadenza:?\s*</strong>[^<]*(.*?)(?=<div|</article)#is', $frag, $m) === 1) {
            $nota = bandi_testo($m[1]);
            // L'URL citato nella nota diventa url_ufficiale: nel testo è rumore.
            $nota = trim((string) preg_replace('#https?://\S+#', '', $nota));
            $nota = trim(str_replace('Leggi tutto', '', $nota));
        }

        $ufficiale = null;
        $links = $xpath->query('.//a[@href]', $node);
        foreach ($links as $a) {
            $href = trim($a->getAttribute('href'));
            if (!str_starts_with($href, 'http') || str_contains($href, 'feampabandionline.it')) {
                continue;
            }
            $ufficiale = news_normalize_url($href);
            break;
        }

        $titolo = bandi_titolo_da_scopo($scopo);

        $voci[news_item_id($permalink)] = bandi_voce([
            'id'                 => news_item_id($permalink),
            'wp_id'              => $wpId,
            'origine'            => $fonteId,
            'regioni'            => bandi_regioni_da_classi($class),
            'priorita'           => $priorita,
            'titolo'             => $titolo !== '' ? $titolo : $priorita,
            'scopo'              => $scopo,
            'codice_intervento'  => $codice,
            'pubblicazione'      => $pubblicazione,
            'scadenza'           => $scadenza,
            'terminato_in_fonte' => bandi_e_terminato($class),
            'nota'               => $nota,
            'url_fonte'          => news_normalize_url($permalink),
            'url_ufficiale'      => $ufficiale,
            // Se mancano sia scadenza sia codice il parsing dei campi non ha
            // funzionato: la voce resta, ma la pagina la segnala come parziale.
            'dettagli_mancanti'  => $scadenza === null && $codice === '',
        ]);
    }

    return array_values($voci);
}

/** Conteggi per slug dall'API categories, usati per riconciliare la copertura. */
function bandi_parse_categorie(string $json): array {
    $data = json_decode($json, true);
    if (!is_array($data)) {
        throw new RuntimeException('Risposta categories non valida');
    }
    $out = [];
    foreach ($data as $c) {
        if (isset($c['slug'], $c['count'])) {
            $out[(string) $c['slug']] = (int) $c['count'];
        }
    }
    return $out;
}

/**
 * Adatta le voci di news_parse_rss() alla forma bando. I feed istituzionali
 * sono feed di sito, non di elenco bandi: danno titolo, data e link, mai la
 * scadenza. Restano quindi segnalazioni, marcate come incomplete.
 */
function bandi_da_feed(array $items, string $regioneSlug, array $keywords): array {
    $pattern = $keywords === []
        ? ''
        : '/' . implode('|', array_map('preg_quote', $keywords)) . '/iu';

    $voci = [];
    foreach ($items as $item) {
        $titolo = bandi_testo((string) $item['title']);
        if ($titolo === '') {
            continue;
        }
        if ($pattern !== '' && preg_match($pattern, $titolo) !== 1) {
            continue;
        }
        $url = (string) $item['url'];
        $voci[news_item_id($url)] = bandi_voce([
            'id'                => news_item_id($url),
            'origine'           => 'istituzionale',
            'regioni'           => [$regioneSlug],
            'titolo'            => $titolo,
            'scopo'             => news_clean_summary((string) ($item['summary'] ?? ''), 300),
            'pubblicazione'     => substr((string) $item['date'], 0, 10),
            'scadenza'          => null,
            'nota'              => '',
            'url_fonte'         => news_normalize_url($url),
            'dettagli_mancanti' => true,
        ]);
    }
    return array_values($voci);
}
