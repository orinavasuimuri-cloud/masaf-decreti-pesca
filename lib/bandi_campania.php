<?php
declare(strict_types=1);

require_once __DIR__ . '/news_normalize.php';
require_once __DIR__ . '/bandi_normalize.php';
require_once __DIR__ . '/bandi_parser.php';

/**
 * Archivio bandi FEAMPA della Regione Campania.
 *
 * È l'unica fonte istituzionale regionale che pubblica i bandi in una tabella
 * HTML invece che in un PDF allegato o in un elenco costruito dal browser: su
 * diciotto regioni verificate, le altre o allegano un calendario in PDF o
 * caricano le voci via JavaScript, e con nessuna delle due si può fare quello
 * che si fa qui.
 *
 * La tabella non elenca bandi ma **atti**: dello stesso bando compaiono
 * l'approvazione, le eventuali proroghe e la graduatoria, una riga ciascuna.
 * Raggrupparli è il lavoro vero di questo file, e il motivo per cui non basta
 * un parser generico di tabelle: pubblicare una voce per riga mostrerebbe lo
 * stesso bando tre volte nella pagina, con tre date diverse e nessuna che sia
 * quella giusta.
 */

/**
 * Le date dei decreti sono nella forma "DRD n. 167 del 06.03.2026", che
 * bandi_parse_data_italiana() non copre: quella legge "5 marzo 2026", con il
 * mese scritto per esteso.
 *
 * Accetta i separatori punto, barra e trattino perché la stessa pagina usa il
 * punto nel testo del decreto e il trattino nel nome del file allegato.
 */
function campania_parse_data_numerica(string $raw): ?string
{
    if (preg_match('~(\d{1,2})[./-](\d{1,2})[./-](\d{2,4})~u', bandi_testo($raw), $m) !== 1) {
        return null;
    }
    $giorno = (int) $m[1];
    $mese   = (int) $m[2];
    $anno   = (int) $m[3];
    // Le date a due cifre stanno nel nome dei file allegati: "26" è 2026, non
    // 1926. Nessun bando FEAMPA è anteriore al 2021.
    if ($anno < 100) {
        $anno += 2000;
    }
    if ($mese < 1 || $mese > 12 || $giorno < 1 || $giorno > 31) {
        return null;
    }
    if (!checkdate($mese, $giorno, $anno)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $anno, $mese, $giorno);
}

/**
 * Gli atti letti dalla tabella, uno per riga, nell'ordine in cui compaiono.
 *
 * Non raggruppa: serve anche da solo, perché è la forma su cui si verifica che
 * la lettura della pagina sia ancora corretta senza passare per la logica di
 * raggruppamento.
 *
 * @return list<array{azione:string, bando:string, descrizione:string, decreto:string, url:?string, data:?string}>
 */
function campania_parse_atti(string $html, string $baseUrl): array
{
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    // La pagina è servita senza dichiarazione di codifica: senza questo
    // prefisso le lettere accentate dei titoli arrivano corrotte.
    $doc->loadHTML('<?xml encoding="UTF-8">' . news_to_utf8($html), LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $x = new DOMXPath($doc);
    $righe = $x->query('//table//tr');
    if ($righe === false || $righe->length === 0) {
        throw new RuntimeException('Nessuna tabella trovata: struttura della pagina Campania cambiata');
    }

    $atti = [];
    foreach ($righe as $riga) {
        $celle = [];
        foreach ($x->query('./td|./th', $riga) as $c) {
            $celle[] = bandi_testo($c->textContent);
        }
        // Le righe a una cella sono le intestazioni di obiettivo specifico, la
        // prima a cinque è quella dei nomi di colonna: si riconoscono dal
        // contenuto e non dalla posizione, perché la pagina ne intercala
        // diverse man mano che aggiunge obiettivi.
        if (count($celle) !== 5) {
            continue;
        }
        if (strcasecmp($celle[0], 'Azione') === 0 && strcasecmp($celle[1], 'Bando') === 0) {
            continue;
        }
        if ($celle[1] === '') {
            continue;
        }

        $url = null;
        $a = $x->query('.//a[@href]', $riga);
        if ($a !== false && $a->length > 0) {
            $href = trim(($a->item(0) instanceof DOMElement) ? $a->item(0)->getAttribute('href') : '');
            if ($href !== '') {
                $url = news_normalize_url(
                    preg_match('~^https?://~i', $href) === 1 ? $href : rtrim($baseUrl, '/') . '/' . ltrim($href, '/')
                );
            }
        }

        $atti[] = [
            'azione'      => $celle[0],
            'bando'       => $celle[1],
            'descrizione' => $celle[2],
            'decreto'     => $celle[3],
            'url'         => $url,
            'data'        => campania_parse_data_numerica($celle[3]),
        ];
    }

    if ($atti === []) {
        throw new RuntimeException('Tabella presente ma nessun atto leggibile: struttura della pagina Campania cambiata');
    }
    return $atti;
}

/**
 * Le voci per l'archivio dei bandi, una per bando invece che una per atto.
 *
 * La data di pubblicazione è quella dell'atto più vecchio del gruppo, cioè
 * l'approvazione; il collegamento e la nota puntano al più recente, che è
 * quello che interessa a chi guarda oggi (spesso una proroga o la graduatoria).
 * Il contrario - data recente e link all'approvazione - darebbe una voce che si
 * sposta in cima all'elenco ogni volta che esce un atto qualsiasi, rimandando a
 * un documento superato.
 *
 * Tutte le voci restano marcate dettagli_mancanti: la tabella dà gli estremi
 * dell'atto, mai il termine di partecipazione. È lo stesso trattamento delle
 * segnalazioni dai feed, ed è il motivo per cui non compaiono fra i bandi
 * "aperti" con una scadenza che non abbiamo.
 *
 * @return list<array>
 */
function campania_bandi_voci(string $html, string $baseUrl, string $pagina): array
{
    $gruppi = [];
    foreach (campania_parse_atti($html, $baseUrl) as $atto) {
        $gruppi[$atto['bando']][] = $atto;
    }

    $voci = [];
    foreach ($gruppi as $bando => $atti) {
        // Ordine cronologico: gli atti senza data leggibile restano in fondo,
        // così non diventano per sbaglio "il più recente" e non portano la
        // voce a puntare a un documento a caso.
        usort($atti, static function (array $a, array $b): int {
            if ($a['data'] === null && $b['data'] === null) { return 0; }
            if ($a['data'] === null) { return 1; }
            if ($b['data'] === null) { return -1; }
            return strcmp($a['data'], $b['data']);
        });

        $conData = array_values(array_filter($atti, static fn(array $a): bool => $a['data'] !== null));
        $primo   = $conData[0] ?? null;
        $ultimo  = $conData !== [] ? $conData[count($conData) - 1] : $atti[0];

        $azioni = [];
        foreach ($atti as $a) {
            if ($a['azione'] !== '') { $azioni[$a['azione']] = true; }
        }

        $nota = trim($ultimo['descrizione'] . ' — ' . $ultimo['decreto'], " —\t\n");
        if (count($atti) > 1) {
            // Dire quanti atti ci sono evita che una voce sembri ferma
            // all'approvazione quando in mezzo sono passate due proroghe.
            $nota .= ' (' . count($atti) . ' atti pubblicati)';
        }

        $voci[] = bandi_voce([
            'id'                => news_item_id('campania-feampa:' . $bando),
            'origine'           => 'istituzionale',
            'regioni'           => ['campania'],
            'titolo'            => (string) $bando,
            'scopo'             => $azioni !== [] ? implode(', ', array_keys($azioni)) : '',
            'pubblicazione'     => $primo['data'] ?? null,
            'scadenza'          => null,
            'nota'              => $nota,
            'url_fonte'         => $pagina,
            'url_ufficiale'     => $ultimo['url'] ?? $pagina,
            'dettagli_mancanti' => true,
            'fonte_label'       => 'Regione Campania · FEAMPA',
        ]);
    }
    return $voci;
}
