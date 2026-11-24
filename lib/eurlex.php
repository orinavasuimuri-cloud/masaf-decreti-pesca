<?php
declare(strict_types=1);

/**
 * Atti UE sulla pesca da EUR-Lex, per la coda "da rivedere" del registro.
 *
 * Il sito EUR-Lex rifiuta i programmi (protezione anti-bot), il servizio SPARQL
 * dell'Ufficio delle pubblicazioni no: stesso archivio (Cellar), titoli italiani,
 * numero CELEX e data. Si chiedono gli atti con un concetto EuroVoc sulla pesca e
 * si tengono quelli che toccano Italia o Mediterraneo o tutta la pesca UE; il resto
 * (Baltico, Atlantico, accordi con Paesi terzi) si scarta. Come per la Gazzetta,
 * nulla entra nel registro senza che l'amministratore lo veda.
 */

require_once __DIR__ . '/rete.php';
require_once __DIR__ . '/curatela.php';

const EURLEX_SPARQL = 'https://publications.europa.eu/webapi/rdf/sparql';

function eurlex_query(string $dal): string
{
    $tipi = implode(',', array_map(
        static fn(string $t): string => "<http://publications.europa.eu/resource/authority/resource-type/$t>",
        ['REG', 'REG_IMPL', 'REG_DEL', 'DEC', 'DEC_IMPL', 'DEC_DEL', 'DIR', 'DIR_IMPL', 'DIR_DEL']
    ));
    return 'PREFIX cdm: <http://publications.europa.eu/ontology/cdm#>
SELECT DISTINCT ?celex ?data ?titolo WHERE {
  ?w cdm:work_has_resource-type ?tipo ; cdm:resource_legal_id_celex ?celex ;
     cdm:work_date_document ?data ; cdm:work_is_about_concept_eurovoc ?c .
  ?c skos:prefLabel ?lab . FILTER(lang(?lab)="it")
  FILTER(REGEX(STR(?lab), "pesca|peschereccio|risorse alieutiche|acquacoltura", "i"))
  FILTER(?data >= "' . $dal . '"^^xsd:date)
  FILTER(?tipo IN (' . $tipi . '))
  ?e cdm:expression_belongs_to_work ?w ; cdm:expression_title ?titolo ;
     cdm:expression_uses_language <http://publications.europa.eu/resource/authority/language/ITA> .
} ORDER BY DESC(?data) LIMIT 500';
}

function eurlex_url_query(string $dal): string
{
    return EURLEX_SPARQL . '?' . http_build_query(['query' => eurlex_query($dal), 'format' => 'text/csv']);
}

/** @return list<array{celex: string, data: string, titolo: string}> */
function eurlex_parse_csv(string $csv): array
{
    $f = fopen('php://temp', 'r+');
    fwrite($f, $csv);
    rewind($f);
    $head = fgetcsv($f, null, ',', '"', '');
    if ($head !== ['celex', 'data', 'titolo']) {
        fclose($f);
        throw new RuntimeException('risposta EUR-Lex inattesa: ' . substr(trim($csv), 0, 120));
    }
    $righe = [];
    while (($r = fgetcsv($f, null, ',', '"', '')) !== false) {
        if (count($r) !== 3 || $r[0] === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $r[1]) !== 1) {
            continue;
        }
        $titolo = trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $r[2])));
        $righe[] = ['celex' => $r[0], 'data' => $r[1], 'titolo' => $titolo];
    }
    fclose($f);
    return $righe;
}

/** Italia, Mediterraneo o tutta la pesca UE; fuori le altre zone e gli altri settori. */
function eurlex_in_tema(string $titolo): bool
{
    $t = str_replace("\u{a0}", ' ', $titolo);
    $luoghi = 'mediterr|itali|cgpm|gfcm|adriatic|ionio|sicilia|tirren|ligure|sardegna';
    $nostro = "/$luoghi/iu";
    $si = "/$luoghi|politica comune della pesca|controllo della pesca|regime di controllo|misure tecniche"
        . '|possibilit. di pesca|contingenti di pesca|sforzo di pesca|obbligo di sbarco|rigetti|feampa'
        . '|fondo europeo per gli affari marittimi|pesca illegale|\bINN\b|tonno rosso|iccat|pesce spada|squal'
        . '|anguilla|corallo|lampuga|flotta|pescherecci|1967\/2006|1224\/2009|2023\/2842|2019\/1241|2023\/2124'
        . '|2023\/2053|1380\/2013|2021\/1139/iu';
    $altrove = '/baltic|midsj|mare del nord|skagerrak|kattegat|biscaglia|atlantico|\bartic[oa]\b|norvegia|islanda'
        . '|groenlandia|isole f[æa]r|regno unito|mauritania|marocco|senegal|guinea|capo verde|seychelles'
        . '|madagascar|maurizio|gabon|liberia|costa d.avorio|s[ãa]o tom|isole cook|kiribati|pacifico'
        . '|oceano indiano|iotc|wcpfc|nafo|neafc|sprfmo|ccamlr|antartic|1907\/2006|veterinar|sostanze chimiche/iu';
    if (preg_match($si, $t) !== 1) {
        return false;
    }
    return preg_match($altrove, $t) !== 1 || preg_match($nostro, $t) === 1;
}

function eurlex_link(string $celex, bool $pdf = false): string
{
    return 'https://eur-lex.europa.eu/legal-content/IT/TXT/' . ($pdf ? 'PDF/' : '') . '?uri=CELEX:' . rawurlencode($celex);
}

/** I nuovi entrano "da rivedere"; quelli già visti tengono lo stato deciso. */
function eurlex_merge(array $store, array $righe, string $oggi): array
{
    foreach ($righe as $r) {
        if (!eurlex_in_tema($r['titolo'])) {
            continue;
        }
        $c = $r['celex'];
        if (isset($store['items'][$c])) {
            $store['items'][$c]['titolo'] = $r['titolo'];
            continue;
        }
        $store['items'][$c] = ['id' => $c, 'celex' => $c, 'data' => $r['data'], 'titolo' => $r['titolo'],
            'url' => eurlex_link($c), 'pdf' => eurlex_link($c, true), 'status' => 'pending_review', 'first_seen' => $oggi];
    }
    return $store;
}

/** @return list<array> */
function eurlex_da_rivedere(array $items, string $status = 'pending_review'): array
{
    $coda = array_values(array_filter($items, static fn(array $v): bool => ($v['status'] ?? '') === $status));
    usort($coda, static fn(array $a, array $b): int => strcmp($b['data'], $a['data']));
    return $coda;
}

/** File assente = archivio vuoto; file rovinato = errore, mai "vuoto" (si perderebbero le decisioni). */
function eurlex_store_load(string $path): array
{
    if (!file_exists($path)) {
        return ['_meta' => ['last_run' => null, 'last_ok' => null], 'items' => []];
    }
    $d = curatela_leggi($path);
    if (!is_array($d['items'] ?? null)) {
        throw new RuntimeException('archivio illeggibile: ' . basename($path));
    }
    return $d;
}

/**
 * Un giro completo: scarica, filtra, unisce, salva. Il lock comune lo prende chi
 * chiama (eurlex_fetcher.php, oppure gazzetta_fetcher.php che lo tiene già).
 */
function eurlex_aggiorna(string $dataDir, callable $log, ?string $oggi = null): void
{
    $oggi ??= date('Y-m-d');
    $path = "$dataDir/eurlex.json";
    $store = eurlex_store_load($path);
    $dal = date('Y-m-d', strtotime("$oggi -120 days"));
    $righe = eurlex_parse_csv(rete_scarica(eurlex_url_query($dal), 'masaf-decreti-pesca/1.0', false, 60));
    $prima = count($store['items']);
    $store = eurlex_merge($store, $righe, $oggi);
    $store['_meta']['last_run'] = date('c');
    $store['_meta']['last_ok'] = date('c');
    curatela_scrivi($path, $store);
    $log('EUR-Lex: ' . count($righe) . ' atti sulla pesca dal ' . $dal . ', ' . (count($store['items']) - $prima)
        . ' nuovi in tema, da rivedere: ' . count(eurlex_da_rivedere($store['items'])));
}
