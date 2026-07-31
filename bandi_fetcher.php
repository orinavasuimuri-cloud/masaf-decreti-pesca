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
$store = bandi_store_load($storeFile);

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

// --- archivio, una regione alla volta ---
$ok = 0;
$ko = 0;
$perRegione = [];
foreach ($regioniCfg['regioni'] as $regione) {
    $slug = (string) $regione['slug'];
    $raccolte = [];
    try {
        for ($pagina = 1; $pagina <= $maxPagine; $pagina++) {
            $url = $pagina === 1
                ? "$baseUrl/regione/$slug/"
                : "$baseUrl/regione/$slug/page/$pagina/";
            try {
                $html = bandi_fetch($url);
                $voci = bandi_parse_archivio($html, 'aggregatore');
            } catch (RuntimeException) {
                // Pagina oltre l'ultima: l'archivio è finito, non è un errore.
                break;
            }
            $raccolte = array_merge($raccolte, $voci);
            if (count($voci) < 10) {
                break;
            }
        }
        if ($raccolte === []) {
            throw new RuntimeException('nessuna voce raccolta');
        }
        $store = bandi_store_merge($store, "regione-$slug", $raccolte, $now);
        $perRegione[$slug] = count($raccolte);
        bandi_log("$slug: " . count($raccolte) . ' voci', $logFile);
        $ok++;
    } catch (Throwable $e) {
        $store = bandi_store_mark_failure($store, "regione-$slug", $e->getMessage(), $now);
        bandi_log("$slug: ERRORE " . $e->getMessage(), $logFile);
        $ko++;
    }
}

// --- categoria nazionale: non è una regione, ma sta nello stesso archivio ---
try {
    $voci = bandi_parse_archivio(bandi_fetch("$baseUrl/regione/bandi-masaf-nazionali/"), 'aggregatore');
    $store = bandi_store_merge($store, 'regione-bandi-masaf-nazionali', $voci, $now);
    $perRegione['bandi-masaf-nazionali'] = count($voci);
    bandi_log('bandi-masaf-nazionali: ' . count($voci) . ' voci', $logFile);
    $ok++;
} catch (Throwable $e) {
    $store = bandi_store_mark_failure($store, 'regione-bandi-masaf-nazionali', $e->getMessage(), $now);
    bandi_log('bandi-masaf-nazionali: ERRORE ' . $e->getMessage(), $logFile);
    $ko++;
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
$store = bandi_store_set_copertura($store, $attesi, $perRegione);

$daAggregatore = count(array_filter(
    $store['items'],
    static fn(array $v): bool => $v['origine'] === 'aggregatore'
));
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
