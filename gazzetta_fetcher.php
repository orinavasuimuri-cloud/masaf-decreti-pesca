<?php
declare(strict_types=1);

/**
 * Scarica i sommari delle serie della Gazzetta Ufficiale, tiene le voci in tema
 * e le smista fra la coda di revisione del registro e la pagina dei bandi.
 *
 * Ogni serie e' isolata: una che fallisce non impedisce alle altre di
 * aggiornarsi. L'archivio non viene mai potato.
 *
 * Uso: php gazzetta_fetcher.php
 */

require_once __DIR__ . '/lib/gazzetta_parser.php';
require_once __DIR__ . '/lib/gazzetta_store.php';
require_once __DIR__ . '/lib/news_normalize.php';
require_once __DIR__ . '/lib/bandi_parser.php';
require_once __DIR__ . '/lib/bandi_store.php';

// date.timezone e' UTC sul server: senza questo ogni timestamp sarebbe sfasato
// di due ore rispetto all'ora italiana.
date_default_timezone_set('Europe/Rome');

$dataDir    = __DIR__ . '/data';
$storeFile  = $dataDir . '/gazzetta.json';
$bandiFile  = $dataDir . '/bandi.json';
$fontiFile  = $dataDir . '/gazzetta_fonti.json';
$logFile    = $dataDir . '/gazzetta.log';

function gz_log(string $msg, string $logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

/** Come negli altri fetcher: senza openssl i wrapper https:// non esistono. */
function gz_fetch(string $url): string {
    $cmd = sprintf(
        'curl -s -L -A %s --max-time 25 %s',
        escapeshellarg('Mozilla/5.0 (Windows NT 10.0; Win64; x64) masaf-decreti-pesca-gazzetta/1.0'),
        escapeshellarg($url)
    );
    $body = shell_exec($cmd);
    if ($body === null || trim($body) === '') {
        throw new RuntimeException("download fallito (curl): $url");
    }
    return $body;
}

$fonti = json_decode((string) @file_get_contents($fontiFile), true);
if (!is_array($fonti) || !isset($fonti['serie']) || !is_array($fonti['serie'])) {
    gz_log('ERRORE: data/gazzetta_fonti.json mancante o non valido', $logFile);
    exit(1);
}

$now  = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('c');
$oggi = date('Y-m-d');

try {
    $store = gazzetta_store_load($storeFile);
} catch (Throwable $e) {
    // Archivio presente ma illeggibile: e' un guasto, non un archivio vuoto.
    // Si esce prima di toccarlo, cosi' un run successivo con il file riparato
    // non lo trova gia' svuotato.
    gz_log('ERRORE caricamento archivio: ' . $e->getMessage(), $logFile);
    exit(1);
}

$ok = 0;
$ko = 0;
$perBandi = [];

foreach ($fonti['serie'] as $serie) {
    $id = (string) $serie['id'];
    try {
        $sommario = gazzetta_parse_sommario(gz_fetch((string) $serie['url']));

        $ultimo = $store['_meta']['serie'][$id]['ultimo_numero'] ?? null;
        $saltati = gazzetta_numeri_saltati($ultimo, $sommario['numero']);
        if ($saltati !== []) {
            gz_log(
                "$id: ATTENZIONE, non visti i fascicoli " . implode(', ', $saltati)
                . ' (dal ' . $ultimo . ' al ' . $sommario['numero'] . ')',
                $logFile
            );
        }

        $voci = gazzetta_voci($sommario, $id, $serie['keywords'] ?? [], $oggi);
        $store = gazzetta_store_merge($store, $id, $voci, $sommario['numero'], $sommario['data'], $now);
        if ($saltati !== []) {
            $store['_meta']['serie'][$id]['saltati'] = array_values(array_unique(array_merge(
                $store['_meta']['serie'][$id]['saltati'] ?? [],
                $saltati
            )));
        }

        foreach ($voci as $voce) {
            if ($voce['destinazione'] === 'bandi') {
                $perBandi[] = $voce;
            }
        }

        gz_log(
            "$id: fascicolo {$sommario['numero']} del {$sommario['data']}, "
            . count($sommario['items']) . ' atti letti, ' . count($voci) . ' in tema',
            $logFile
        );
        $ok++;
    } catch (Throwable $e) {
        $store = gazzetta_store_mark_failure($store, $id, $e->getMessage(), $now);
        gz_log("$id: ERRORE " . $e->getMessage(), $logFile);
        $ko++;
    }
}

$store['_meta']['last_run'] = $now;

try {
    gazzetta_store_save($storeFile, $store);
} catch (Throwable $e) {
    gz_log('ERRORE salvataggio: ' . $e->getMessage(), $logFile);
    exit(1);
}

// --- travaso verso la pagina dei bandi ---
// Le voci classificate come bandi entrano nell'archivio dei bandi con la stessa
// forma delle segnalazioni dai feed: senza scadenza e marcate come incomplete,
// perche' la GU pubblica l'atto, non il termine di partecipazione.
if ($perBandi !== []) {
    try {
        $bandi = bandi_store_load($bandiFile);
        $vociBandi = [];
        foreach ($perBandi as $voce) {
            $vociBandi[] = bandi_voce([
                'id'                => news_item_id($voce['url']),
                'origine'           => 'gazzetta',
                'regioni'           => [],
                'titolo'            => $voce['oggetto'],
                'scopo'             => $voce['oggetto'],
                'pubblicazione'     => $voce['data_gu'],
                'scadenza'          => null,
                'nota'              => 'Pubblicato in Gazzetta Ufficiale n. ' . $voce['numero_gu'] . ' del ' . $voce['data_gu'],
                'url_fonte'         => $voce['url'],
                'url_ufficiale'     => $voce['url'],
                'dettagli_mancanti' => true,
                'fonte_label'       => 'Gazzetta Ufficiale',
            ]);
        }
        $bandi = bandi_store_merge($bandi, 'gu-sg', $vociBandi, $now);
        bandi_store_save($bandiFile, $bandi);
        gz_log(count($vociBandi) . ' voci travasate nella pagina dei bandi', $logFile);
    } catch (Throwable $e) {
        // Il travaso fallito non annulla la raccolta: l'archivio GU e' gia'
        // salvato e le voci restano, il prossimo run ritenta.
        gz_log('ERRORE travaso bandi: ' . $e->getMessage(), $logFile);
    }
}

$daRivedere = count(array_filter(
    $store['items'],
    static fn(array $v): bool => $v['status'] === 'pending_review' && $v['destinazione'] === 'registro'
));
gz_log("Serie ok: $ok, fallite: $ko, voci in archivio: " . count($store['items']) . ", da rivedere: $daRivedere", $logFile);
exit($ok > 0 ? 0 : 1);
