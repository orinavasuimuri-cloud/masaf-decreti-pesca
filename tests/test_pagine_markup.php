<?php
declare(strict_types=1);

/**
 * Contratto di markup fra le pagine e assets/filters.js.
 *
 * filters.js non legge il DOM per classe ma per attributo: se una pagina
 * smette di emettere data-search su una voce, o rinomina l'attributo dei
 * chip senza aggiornare initFilters(), la ricerca continua a caricarsi
 * senza errori e semplicemente non trova piu' nulla. E' un guasto muto,
 * che nessun test PHP sui parser intercetta: questi controlli lo rendono
 * rumoroso.
 *
 * Le pagine si rendono in un sottoprocesso e non con include: registro.php,
 * news.php e bandi.php dichiarano tutte una funzione h(), includerne due
 * nello stesso processo sarebbe un errore fatale di ridichiarazione.
 */

function pm_render(string $page): string
{
    $file = dirname(__DIR__) . '/' . $page;
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file));
    return is_string($out) ? $out : '';
}

function pm_xpath(string $html): DOMXPath
{
    $doc = new DOMDocument();
    // Le pagine sono HTML5, libxml segnala tag che non conosce: gli avvisi
    // non interessano, conta solo l'albero che ne esce.
    @$doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    return new DOMXPath($doc);
}

function pm_count(DOMXPath $x, string $query): int
{
    $r = $x->query($query);
    return $r === false ? -1 : $r->length;
}

/** Valori distinti di un attributo, nell'ordine in cui compaiono. */
function pm_valori(DOMXPath $x, string $query, string $attr): array
{
    $out = [];
    foreach ($x->query($query) as $node) {
        // query() e' tipizzata DOMNode: solo DOMElement espone gli attributi.
        if ($node instanceof DOMElement) {
            $out[$node->getAttribute($attr)] = true;
        }
    }
    return array_keys($out);
}

/**
 * 'chip' dice da dove nascono i bottoni di filtro, perche' cambia cosa si puo'
 * pretendere dal rapporto fra chip e voci:
 *   esatto      i chip sono generati scorrendo le voci (gli anni in registro.php),
 *               quindi le due liste devono coincidere in entrambe le direzioni;
 *   copre-voci  i chip vengono dalla configurazione delle fonti (news.php): una
 *               fonte appena aggiunta o momentaneamente a secco ha un chip senza
 *               notizie, ed e' legittimo. Il contrario no: una notizia la cui
 *               fonte non ha un chip sarebbe irraggiungibile dal filtro;
 *   almeno-uno  i chip sono scelti a mano (bandi.php offre solo "Tutti" e "Solo
 *               aperti", non uno stato per bottone): si puo' solo pretendere che
 *               il valore usato esista davvero fra le voci.
 */
$pagine = [
    'registro.php' => ['itemAttr' => 'data-year',  'chipAttr' => 'data-yr',    'chip' => 'esatto'],
    'news.php'  => ['itemAttr' => 'data-src',   'chipAttr' => 'data-src',   'chip' => 'copre-voci'],
    'bandi.php' => ['itemAttr' => 'data-stato', 'chipAttr' => 'data-stato', 'chip' => 'almeno-uno'],
];

$html = [];
foreach ($pagine as $page => $cfg) {
    $html[$page] = pm_render($page);
    $x = pm_xpath($html[$page]);

    t_true($html[$page] !== '', "$page: la pagina non produce output");

    // --- la searchbar e' emessa una volta sola: gli id sono il contratto con filters.js
    foreach (['q', 'q-clear', 'q-status', 'no-results'] as $id) {
        t_eq(pm_count($x, "//*[@id='$id']"), 1, "$page: #$id deve comparire una volta sola");
    }

    // --- lo script c'e' e viene invocato
    t_eq(substr_count($html[$page], 'assets/filters.js'), 1, "$page: filters.js incluso una volta sola");
    t_eq(substr_count($html[$page], 'initFilters('), 1, "$page: initFilters invocato una volta sola");

    // --- ogni voce filtrabile e' indicizzata
    $voci = pm_count($x, '//*[@data-search]');
    t_true($voci > 0, "$page: nessuna voce indicizzata dalla ricerca");
    t_eq(pm_count($x, "//*[@data-search='']"), 0, "$page: voci con data-search vuoto");

    // --- chip e voci parlano dello stesso vocabolario di valori
    $chip = array_values(array_diff(
        pm_valori($x, "//*[contains(@class,'yr-chip')][@{$cfg['chipAttr']}]", $cfg['chipAttr']),
        ['all']
    ));
    t_true(count($chip) > 0, "$page: nessun chip di filtro emesso");
    $valoriVoci = pm_valori($x, "//*[@data-search][@{$cfg['itemAttr']}]", $cfg['itemAttr']);

    if ($cfg['chip'] !== 'almeno-uno') {
        // Una voce il cui valore non ha un chip resta fuori da ogni filtro:
        // vale sia quando i chip nascono dai dati sia quando vengono dalla
        // configurazione, ed e' il verso che segnala una rinomina a meta'.
        $scoperte = array_values(array_diff($valoriVoci, $chip));
        t_eq($scoperte, [], "$page: voci senza un chip che le selezioni: " . implode(', ', $scoperte));
    }
    if ($cfg['chip'] === 'esatto') {
        $orfani = array_values(array_diff($chip, $valoriVoci));
        t_eq($orfani, [], "$page: chip che non corrispondono a nessuna voce: " . implode(', ', $orfani));
    }
    if ($cfg['chip'] === 'almeno-uno') {
        t_true(
            count(array_intersect($chip, $valoriVoci)) > 0,
            "$page: nessun chip usa un valore presente fra le voci"
        );
    }
}

// --- index: le uniche voci senza anno sono gli atti da rivedere,
//     che filters.js lascia apposta fuori dal filtro a chip.
$x = pm_xpath($html['registro.php']);
t_eq(
    pm_count($x, "//*[@data-search][not(@data-year)][not(ancestor::*[contains(@class,'pending-box')])]"),
    0,
    'registro.php: voci senza data-year fuori dal riquadro "da rivedere"'
);

// --- index: ogni PDF del catalogo resta scaricabile dal riquadro del decreto.
//     Guardia sulla rimozione della vecchia sezione "Documenti scaricabili":
//     era l'unico punto da cui si scaricava, ora l'unico e' il riquadro.
$catalog = json_decode((string) file_get_contents(dirname(__DIR__) . '/data/catalog.json'), true);
$pdfAttesi = [];
$allegatiAttesi = [];
foreach ($catalog['sections'] ?? [] as $s) {
    foreach ($s['items'] ?? [] as $i) {
        if (!empty($i['pdf'])) {
            $pdfAttesi[$i['pdf']] = true;
        }
        foreach ($i['allegati'] ?? [] as $a) {
            if (!empty($a['pdf'])) {
                $allegatiAttesi[$a['pdf']] = true;
            }
        }
    }
}
// Solo il bottone del decreto, non quelli degli allegati: da quando anche gli
// allegati sono resi come bottone condividono la classe 'dl', e il confronto va
// fatto sui due insiemi separati (gli allegati hanno il loro, piu' sotto).
$pdfInPagina = pm_valori($x, "//div[contains(@class,'foot-main')]/a[contains(@class,'dl')]", 'href');
sort($pdfInPagina);
$attesi = array_keys($pdfAttesi);
sort($attesi);
t_eq($pdfInPagina, $attesi, 'registro.php: i PDF scaricabili in pagina non coincidono con quelli del catalogo');

// Gli allegati agganciati a una voce (elenchi, note, manuali) sono raggiungibili
// solo da qui: se il riquadro smette di renderli non esiste altra strada.
$allegatiInPagina = pm_valori($x, "//ul[contains(@class,'allegati')]//a", 'href');
sort($allegatiInPagina);
$attesiAll = array_keys($allegatiAttesi);
sort($attesiAll);
t_true(count($attesiAll) > 0, 'registro.php: il catalogo non ha allegati agganciati, il controllo non verifica nulla');
t_eq($allegatiInPagina, $attesiAll, 'registro.php: gli allegati in pagina non coincidono con quelli del catalogo');

// Ogni allegato deve dire cosa contiene: titoli come "Allegato 1" o un numero
// di protocollo non bastano a chi sta per scaricare.
$mute = [];
foreach ($catalog['sections'] ?? [] as $s) {
    foreach ($s['items'] ?? [] as $i) {
        foreach ($i['allegati'] ?? [] as $a) {
            if (trim((string) ($a['desc'] ?? '')) === '') {
                $mute[] = ($i['ref'] ?? '?') . ' → ' . ($a['titolo'] ?? '?');
            }
        }
    }
}
t_eq($mute, [], 'catalog.json: allegati senza descrizione, non si sa cosa si scarica');
$descInPagina = pm_count($x, "//ul[contains(@class,'allegati')]//*[contains(@class,'all-desc')]");
t_eq($descInPagina, count($attesiAll), 'registro.php: non tutte le descrizioni degli allegati arrivano in pagina');

// Il contatore in intestazione conta i file, non le voci che ne hanno almeno uno.
$totale = count($attesi) + count($attesiAll);
t_true(
    str_contains($html['registro.php'], 'Documenti PDF: <strong>' . $totale . '</strong>'),
    "registro.php: l'intestazione non dichiara $totale documenti scaricabili"
);
t_eq(pm_count($x, "//*[@id='documenti']"), 0, 'registro.php: la sezione "Documenti scaricabili" e\' stata rimossa');

// --- bandi: data-nocount solo sulle righe "in scadenza", che ripetono
//     bandi gia' contati piu' sotto. Altrove falserebbe il totale.
$xb = pm_xpath($html['bandi.php']);
t_true(pm_count($xb, "//*[@data-nocount]") > 0, 'bandi.php: nessuna riga marcata data-nocount');
t_eq(
    pm_count($xb, "//*[@data-nocount][not(ancestor-or-self::*[contains(@class,'bandi-aperti')])]"),
    0,
    'bandi.php: data-nocount fuori dal riquadro "in scadenza"'
);

// --- news: ogni notizia porta la fonte, altrimenti i chip non la filtrano
$xn = pm_xpath($html['news.php']);
t_eq(pm_count($xn, '//*[@data-search][not(@data-src)]'), 0, 'news.php: notizie senza data-src');

// --- index.php: prima pagina. Non ha ricerca ne' chip (e' una vetrina, non
//     una pagina di consultazione), quindi resta fuori dal giro sopra: qui si
//     controlla che regga i dati veri e non perda pezzi per strada.
$htmlG = pm_render('index.php');
t_true($htmlG !== '', 'index.php: la pagina non produce output');
$xg = pm_xpath($htmlG);

// Un solo pezzo di apertura, con capolettera: due significherebbe che il
// blocco e' stampato dentro un ciclo per errore.
t_eq(pm_count($xg, "//*[contains(@class,'gz-lead')]"), 1, 'index.php: apertura non unica');

// Ogni categoria con voci ha il suo blocco e la sua ancora nel menu.
$sezioniConVoci = 0;
foreach (($catalog['sections'] ?? []) as $s) {
    if (!empty($s['items'])) {
        $sezioniConVoci++;
        t_eq(pm_count($xg, "//*[@id='s-" . $s['id'] . "']"), 1, "index.php: manca il blocco della categoria {$s['id']}");
    }
}
t_true($sezioniConVoci > 0, 'index.php: nessuna categoria con voci, il controllo non verifica nulla');

// Il ticker stampa l'elenco due volte per non far vedere il salto: la seconda
// copia deve essere nascosta ai lettori di schermo e non raggiungibile da
// tastiera, altrimenti gli stessi titoli si leggono e si tabulano due volte.
$tickerTot = pm_count($xg, "//*[contains(@class,'nastro')]/a");
$tickerNascosti = pm_count($xg, "//*[contains(@class,'nastro')]/a[@aria-hidden='true'][@tabindex='-1']");
t_true($tickerTot > 0, 'index.php: ticker vuoto');
t_eq($tickerNascosti * 2, $tickerTot, 'index.php: la copia del ticker non e\' nascosta ad assistive/tastiera');

// Nessun avviso PHP stampato in pagina: con strict_types una data corrotta
// nei feed puo' far uscire un warning dentro il markup.
t_eq(
    preg_match('/(Warning|Fatal error|Notice|Deprecated):/', $htmlG),
    0,
    'index.php: la pagina stampa avvisi PHP'
);
