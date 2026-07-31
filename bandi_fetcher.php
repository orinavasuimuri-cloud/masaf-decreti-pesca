<?php
declare(strict_types=1);

/**
 * Scarica i bandi dall'aggregatore FEAMPA e dai feed istituzionali, li
 * normalizza e li unisce a data/bandi.json.
 *
 * Ogni regione e ogni feed sono isolati: una fonte che fallisce non impedisce
 * alle altre di aggiornarsi. L'archivio non viene mai potato.
 *
 * Uso: php bandi_fetcher.php
 */

require_once __DIR__ . '/lib/news_normalize.php';
require_once __DIR__ . '/lib/news_parsers.php';
require_once __DIR__ . '/lib/bandi_normalize.php';
require_once __DIR__ . '/lib/bandi_parser.php';
require_once __DIR__ . '/lib/bandi_store.php';

// date.timezone è UTC sul server: senza questo ogni timestamp del log e di
// _meta (last_run, last_ok) sarebbe sfasato di due ore rispetto all'ora
// italiana. Su un job non sorvegliato dal Task Scheduler il log è la prima
// cosa che si guarda.
date_default_timezone_set('Europe/Rome');

$dataDir     = __DIR__ . '/data';
$storeFile   = $dataDir . '/bandi.json';
$fontiFile   = $dataDir . '/bandi_fonti.json';
$regioniFile = $dataDir . '/bandi_regioni.json';
$logFile     = $dataDir . '/bandi.log';

function bandi_log(string $msg, string $logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

/** Come in scraper.php e news_fetcher.php: senza openssl i wrapper https:// non esistono. */
function bandi_fetch(string $url, bool $conHeader = false): string {
    $cmd = sprintf(
        'curl -s %s -L -A %s --max-time 25 %s',
        $conHeader ? '-i' : '',
        escapeshellarg('Mozilla/5.0 (Windows NT 10.0; Win64; x64) masaf-decreti-pesca-scraper/1.0'),
        escapeshellarg($url)
    );
    $body = shell_exec($cmd);
    if ($body === null || trim($body) === '') {
        throw new RuntimeException("download fallito (curl): $url");
    }
    return $body;
}

/**
 * X-WP-Total dice quanti post esistono in tutto: è il numero con cui si
 * riconcilia la raccolta. Sta solo negli header, quindi qui si scarica con -i.
 */
function bandi_totale_api(string $baseUrl): int {
    $risposta = bandi_fetch($baseUrl . '/wp-json/wp/v2/posts?per_page=1&_fields=id', true);
    if (preg_match('/^X-WP-Total:\s*(\d+)/mi', $risposta, $m) === 1) {
        return (int) $m[1];
    }
    throw new RuntimeException('header X-WP-Total assente');
}

$fonti = json_decode((string) @file_get_contents($fontiFile), true);
$regioniCfg = json_decode((string) @file_get_contents($regioniFile), true);
if (!is_array($fonti) || !isset($fonti['aggregatore']) || !is_array($regioniCfg)) {
    bandi_log('ERRORE: data/bandi_fonti.json o data/bandi_regioni.json mancante o non valido', $logFile);
    exit(1);
}

$now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('c');
try {
    $store = bandi_store_load($storeFile);
} catch (Throwable $e) {
    // Archivio presente ma illeggibile o non decodificabile: non è "vuoto",
    // è un guasto. Si esce subito, prima di toccare $storeFile, così un run
    // successivo con il file riparato non trova un archivio già svuotato.
    bandi_log('ERRORE caricamento archivio: ' . $e->getMessage(), $logFile);
    exit(1);
}

$agg = $fonti['aggregatore'];
$baseUrl = rtrim((string) $agg['base_url'], '/');
$maxPagine = (int) ($agg['max_pagine_per_regione'] ?? 10);

// --- censimento: quanti post dichiara la fonte, e come sono distribuiti ---
$attesi = 0;
$conteggi = [];
try {
    $attesi = bandi_totale_api($baseUrl);
    $conteggi = bandi_parse_categorie(bandi_fetch($baseUrl . '/wp-json/wp/v2/categories?per_page=100'));
    bandi_log("API: $attesi post dichiarati, " . count($conteggi) . ' categorie', $logFile);
} catch (Throwable $e) {
    bandi_log('API: ERRORE ' . $e->getMessage() . ' (si prosegue con le pagine archivio)', $logFile);
}

// --- archivio, una regione (o la sezione nazionale) alla volta ---
// "bandi-masaf-nazionali" non è una regione, ma vive nello stesso archivio
// paginato dell'aggregatore, con lo stesso URL /regione/<slug>/[page/N/]. Un
// blocco separato senza paginazione lasciava sparire in silenzio ogni voce
// nazionale oltre la decima: un solo ciclo su tutti gli slug - regioni più
// nazionale - applica la stessa paginazione a entrambi ed elimina la
// duplicazione fra i due blocchi.
$slugArchivio = array_map(static fn(array $r): string => (string) $r['slug'], $regioniCfg['regioni']);
$slugArchivio[] = 'bandi-masaf-nazionali';

$ok = 0;
$ko = 0;
$perRegione = [];
foreach ($slugArchivio as $slug) {
    $raccolte = [];
    try {
        for ($pagina = 1; $pagina <= $maxPagine; $pagina++) {
            $url = $pagina === 1
                ? "$baseUrl/regione/$slug/"
                : "$baseUrl/regione/$slug/page/$pagina/";
            // Il fetch resta fuori dal try qui sotto: un errore di rete (timeout,
            // DNS, connessione interrotta) non è la fine dell'archivio, è un
            // guasto della regione. Deve risalire al catch esterno, che lo logga
            // e lo registra in _meta.fonti, non essere scambiato per silenzio.
            $html = bandi_fetch($url);
            try {
                $voci = bandi_parse_archivio($html, 'aggregatore');
            } catch (RuntimeException) {
                // Nessun articolo nella risposta: pagina oltre l'ultima, l'archivio
                // è finito. Questo sì è normale, e interrompe il ciclo in silenzio.
                break;
            }
            $raccolte = array_merge($raccolte, $voci);
            if (count($voci) < 10) {
                break;
            }
        }
        if ($raccolte === [] && ($conteggi[$slug] ?? null) !== 0) {
            // Un archivio vuoto è un guasto (pagina non raggiunta, markup
            // cambiato) SOLO se il censimento non conferma esplicitamente zero.
            // Quando lo conferma (Valle d'Aosta, Trentino-Alto Adige: zero bandi
            // legittimi) trattarlo da errore produce un allarme che non si
            // spegne mai, e un allarme perenne insegna a ignorare gli allarmi.
            throw new RuntimeException('nessuna voce raccolta');
        }
        $store = bandi_store_merge($store, "regione-$slug", $raccolte, $now);
        $perRegione[$slug] = count($raccolte);
        bandi_log("$slug: " . count($raccolte) . ' voci', $logFile);
        $ok++;
    } catch (Throwable $e) {
        $store = bandi_store_mark_failure($store, "regione-$slug", $e->getMessage(), $now);
        // null, non voce assente: senza questo la regione fallita sparisce da
        // _meta.copertura.per_regione e "zero bandi" (successo) diventa
        // indistinguibile da "non interrogata" (guasto).
        $perRegione[$slug] = null;
        bandi_log("$slug: ERRORE " . $e->getMessage(), $logFile);
        $ko++;
    }
}

// --- feed istituzionali ---
foreach ($fonti['feed'] as $feed) {
    $id = (string) $feed['id'];
    try {
        $items = news_parse_rss(bandi_fetch((string) $feed['url']), $id, $now);
        $voci = bandi_da_feed($items, (string) $feed['regione'], $feed['keywords'] ?? []);
        $store = bandi_store_merge($store, $id, $voci, $now);
        bandi_log("$id: " . count($voci) . ' segnalazioni in tema su ' . count($items) . ' voci', $logFile);
        $ok++;
    } catch (Throwable $e) {
        $store = bandi_store_mark_failure($store, $id, $e->getMessage(), $now);
        bandi_log("$id: ERRORE " . $e->getMessage(), $logFile);
        $ko++;
    }
}

$store['_meta']['last_run'] = $now;
$store = bandi_store_set_copertura($store, $attesi, $perRegione, $conteggi);

// Riconciliazione anche per regione, non solo sul totale: un conteggio
// raccolto inferiore al dichiarato dal censimento per categoria segnala che
// la paginazione si è fermata prima del previsto per quella singola regione,
// uno scarto che il solo confronto aggregato su $attesi potrebbe nascondere.
foreach ($perRegione as $slug => $raccolte) {
    $dichiarati = $conteggi[$slug] ?? null;
    if ($raccolte !== null && $dichiarati !== null && $raccolte < $dichiarati) {
        bandi_log(
            "ATTENZIONE: $slug ha $raccolte voci raccolte contro $dichiarati dichiarate dal censimento per categoria",
            $logFile
        );
    }
}

// _meta.copertura.raccolti conta solo le voci di origine aggregatore (vedi
// bandi_store_set_copertura): è lo stesso criterio con cui si confronta $attesi,
// mentre count($store['items']) includerebbe anche le segnalazioni dai feed,
// che l'API non censisce e che l'archivio accumula senza mai potarle.
$daAggregatore = $store['_meta']['copertura']['raccolti'];
if ($attesi > 0 && $daAggregatore < $attesi) {
    bandi_log("ATTENZIONE: raccolte $daAggregatore voci su $attesi dichiarate dall'API", $logFile);
}

try {
    bandi_store_save($storeFile, $store);
} catch (Throwable $e) {
    bandi_log('ERRORE salvataggio: ' . $e->getMessage(), $logFile);
    exit(1);
}

bandi_log("Fonti ok: $ok, fallite: $ko, voci totali: " . count($store['items']), $logFile);
exit($ok > 0 ? 0 : 1);
