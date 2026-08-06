<?php
declare(strict_types=1);

/**
 * Scarica le fonti configurate in data/news_sources.json, normalizza le voci e
 * le unisce a data/news.json.
 *
 * Ogni fonte è isolata: una che fallisce non impedisce alle altre di aggiornarsi.
 * Se cadono tutte, si aggiorna solo _meta (salute e last_run) e gli items restano
 * intatti, così la pagina continua a mostrare le notizie già raccolte e l'avviso
 * di fonte ferma può comunque scattare.
 *
 * Uso: php news_fetcher.php
 */

require_once __DIR__ . '/lib/news_normalize.php';
require_once __DIR__ . '/lib/news_parsers.php';
require_once __DIR__ . '/lib/news_store.php';

// date.timezone è UTC sul server: senza questo ogni timestamp del log e di
// _meta (last_run, last_ok) sarebbe sfasato di due ore rispetto all'ora
// italiana. Su un job non sorvegliato dal Task Scheduler il log è la prima
// cosa che si guarda.
date_default_timezone_set('Europe/Rome');

$dataDir    = __DIR__ . '/data';
$storeFile  = $dataDir . '/news.json';
$sourceFile = $dataDir . '/news_sources.json';
$logFile    = $dataDir . '/news.log';

function news_log(string $msg, string $logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

require_once __DIR__ . '/lib/lock.php';
// Lo stesso lock di tutti i fetcher: data/news.json lo scrive solo questo
// programma, ma due sue esecuzioni sovrapposte si annullerebbero a vicenda.
lock_o_esci($dataDir . '/.fetch.lock', static function (string $m) use ($logFile): void {
    news_log($m, $logFile);
});

/** Come in scraper.php: senza openssl i wrapper https:// non esistono. */
function news_fetch_url(string $url): string {
    $cmd = sprintf(
        'curl -s -L -A %s --max-time 25 %s',
        escapeshellarg('Mozilla/5.0 (Windows NT 10.0; Win64; x64) masaf-decreti-pesca-scraper/1.0'),
        escapeshellarg($url)
    );
    $body = shell_exec($cmd);
    if ($body === null || $body === '') {
        throw new RuntimeException("download fallito (curl): $url");
    }
    return $body;
}

$config = json_decode((string) @file_get_contents($sourceFile), true);
if (!is_array($config) || empty($config['sources'])) {
    news_log('ERRORE: data/news_sources.json mancante o non valido', $logFile);
    exit(1);
}

$now   = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('c');
try {
    $store = news_store_load($storeFile);
} catch (Throwable $e) {
    // Archivio presente ma illeggibile: e' un guasto, non un archivio vuoto.
    // Si esce prima di toccarlo - come in gazzetta_fetcher.php - cosi' un giro
    // successivo con il file riparato non lo trova gia' sovrascritto con le
    // sole voci di oggi.
    news_log('ERRORE caricamento archivio: ' . $e->getMessage(), $logFile);
    exit(1);
}

$ok = 0;
$ko = 0;
foreach ($config['sources'] as $source) {
    $id = (string) $source['id'];
    try {
        $body = news_fetch_url((string) $source['url']);
        $items = match ((string) $source['type']) {
            'rss'   => news_parse_rss($body, $id, $now),
            'masaf' => news_parse_masaf($body, $id, $source['keywords'] ?? [], $now),
            default => throw new RuntimeException("tipo fonte sconosciuto: {$source['type']}"),
        };
        $before = count($store['items']);
        $store  = news_store_merge($store, $id, $items, $now);
        $nuove  = count($store['items']) - $before;
        news_log("$id: " . count($items) . " voci lette, $nuove nuove", $logFile);
        $ok++;
    } catch (Throwable $e) {
        $store = news_store_mark_failure($store, $id, $e->getMessage(), $now);
        news_log("$id: ERRORE " . $e->getMessage(), $logFile);
        $ko++;
    }
}

$store['_meta']['last_run'] = $now;
if ($ok > 0) {
    $store = news_store_prune($store, $now);
}

try {
    news_store_save($storeFile, $store);
} catch (Throwable $e) {
    news_log('ERRORE salvataggio: ' . $e->getMessage(), $logFile);
    exit(1);
}

news_log("Fonti ok: $ok, fallite: $ko, voci totali: " . count($store['items']), $logFile);
exit($ok > 0 ? 0 : 1);
