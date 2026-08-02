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
 * Le pagine si rendono in un sottoprocesso e non con include: index.php,
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
 *   esatto      i chip sono generati scorrendo le voci (gli anni in index.php),
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
    'index.php' => ['itemAttr' => 'data-year',  'chipAttr' => 'data-yr',    'chip' => 'esatto'],
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
$x = pm_xpath($html['index.php']);
t_eq(
    pm_count($x, "//*[@data-search][not(@data-year)][not(ancestor::*[contains(@class,'pending-box')])]"),
    0,
    'index.php: voci senza data-year fuori dal riquadro "da rivedere"'
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
$pdfInPagina = pm_valori($x, "//a[contains(@class,'dl')]", 'href');
sort($pdfInPagina);
$attesi = array_keys($pdfAttesi);
sort($attesi);
t_eq($pdfInPagina, $attesi, 'index.php: i PDF scaricabili in pagina non coincidono con quelli del catalogo');

// Gli allegati agganciati a una voce (elenchi, note, manuali) sono raggiungibili
// solo da qui: se il riquadro smette di renderli non esiste altra strada.
$allegatiInPagina = pm_valori($x, "//ul[contains(@class,'allegati')]//a", 'href');
sort($allegatiInPagina);
$attesiAll = array_keys($allegatiAttesi);
sort($attesiAll);
t_true(count($attesiAll) > 0, 'index.php: il catalogo non ha allegati agganciati, il controllo non verifica nulla');
t_eq($allegatiInPagina, $attesiAll, 'index.php: gli allegati in pagina non coincidono con quelli del catalogo');

// Il contatore in intestazione conta i file, non le voci che ne hanno almeno uno.
$totale = count($attesi) + count($attesiAll);
t_true(
    str_contains($html['index.php'], 'Documenti PDF: <strong>' . $totale . '</strong>'),
    "index.php: l'intestazione non dichiara $totale documenti scaricabili"
);
t_eq(pm_count($x, "//*[@id='documenti']"), 0, 'index.php: la sezione "Documenti scaricabili" e\' stata rimossa');

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
