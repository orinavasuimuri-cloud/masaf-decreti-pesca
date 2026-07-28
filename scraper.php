<?php
/**
 * Scarica l'indice normativa pesca MASAF dell'anno corrente, estrae le voci
 * elenco (link con classe "u-textClean u-color-50", distinti dai link di
 * menu/navigazione della pagina) e le confronta con data/known.json.
 *
 * Non categorizza né descrive i contenuti: le voci non ancora presenti in
 * data/catalog.json vengono segnate "pending_review" per verifica manuale.
 *
 * Uso: php scraper.php
 */

declare(strict_types=1);

$baseDir    = __DIR__;
$dataDir    = $baseDir . '/data';
$knownFile  = $dataDir . '/known.json';
$catalogFile = $dataDir . '/catalog.json';
$excludedFile = $dataDir . '/excluded.json';
$logFile    = $dataDir . '/scraper.log';

$year = date('Y');
$indexUrl = "https://www.masaf.gov.it/flex/cm/pages/ServeBLOB.php/L/IT/IDPagina/156/YY/{$year}";

function log_line(string $msg, string $logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

/**
 * Il modulo openssl di PHP non è abilitato in questa installazione (richiederebbe
 * modificare il php.ini condiviso di sistema), quindi file_get_contents non può
 * aprire URL https://. Usiamo l'eseguibile curl di sistema, già disponibile.
 */
function fetch_url(string $url): string {
    $cmd = sprintf(
        'curl -s -A %s --max-time 25 %s',
        escapeshellarg('Mozilla/5.0 (Windows NT 10.0; Win64; x64) masaf-decreti-pesca-scraper/1.0'),
        escapeshellarg($url)
    );
    $html = shell_exec($cmd);
    if ($html === null || $html === '') {
        throw new RuntimeException("Impossibile scaricare (curl): $url");
    }
    return $html;
}

/**
 * @return array<int, array{id:int, url:string, title:string}>
 */
function parse_entries(string $html): array {
    // DOMDocument senza mbstring: forziamo l'interpretazione UTF-8 con il
    // prefisso XML, indipendentemente da come il server dichiara il charset.
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query(
        '//a[contains(concat(" ", normalize-space(@class), " "), " u-textClean ") ' .
        'and contains(concat(" ", normalize-space(@class), " "), " u-color-50 ")]'
    );

    $entries = [];
    foreach ($nodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $href = $node->getAttribute('href');
        if (!preg_match('/IDPagina\/(\d+)/', $href, $m)) {
            continue;
        }
        $id = (int) $m[1];

        $title = $node->textContent;
        $title = preg_replace('/\s+/u', ' ', $title);
        $title = trim($title);

        $entries[$id] = ['id' => $id, 'url' => $href, 'title' => $title];
    }
    return array_values($entries);
}

function load_json(string $path, $default) {
    if (!file_exists($path)) {
        return $default;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return $data ?? $default;
}

function save_json(string $path, $data): void {
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// --- esecuzione ---

log_line("Verifica indice MASAF $year: $indexUrl", $logFile);

try {
    $html = fetch_url($indexUrl);
} catch (Throwable $e) {
    log_line('ERRORE fetch: ' . $e->getMessage(), $logFile);
    exit(1);
}

$entries = parse_entries($html);
log_line('Voci trovate nell\'indice: ' . count($entries), $logFile);

$known    = load_json($knownFile, ['_meta' => ['last_run' => null], 'items' => []]);
$catalog  = load_json($catalogFile, ['sections' => []]);
$excluded = load_json($excludedFile, ['ids' => []]);

$catalogIds = [];
foreach ($catalog['sections'] ?? [] as $section) {
    foreach ($section['items'] ?? [] as $item) {
        if (!empty($item['masaf_id'])) {
            $catalogIds[] = (int) $item['masaf_id'];
        }
    }
}
$excludedIds = $excluded['ids'] ?? [];

$newCount = 0;
foreach ($entries as $entry) {
    $id = $entry['id'];

    if (isset($known['items'][$id])) {
        // Aggiorna solo titolo/url, mantiene lo stato già assegnato.
        $known['items'][$id]['title'] = $entry['title'];
        $known['items'][$id]['url']   = $entry['url'];
        continue;
    }

    if (in_array($id, $catalogIds, true)) {
        $status = 'curated'; // già presente nel catalogo curato
    } elseif (in_array($id, $excludedIds, true)) {
        $status = 'excluded'; // già valutato e scartato in passato
    } else {
        $status = 'pending_review'; // nuovo, mai visto: da rivedere
        $newCount++;
    }

    $known['items'][$id] = [
        'id' => $id,
        'url' => $entry['url'],
        'title' => $entry['title'],
        'status' => $status,
        'first_seen' => date('Y-m-d'),
    ];
}

$known['_meta']['last_run'] = date('c');
$known['_meta']['total_on_index'] = count($entries);

save_json($knownFile, $known);

log_line("Nuove voci da rivedere: $newCount", $logFile);
if ($newCount > 0) {
    foreach ($known['items'] as $item) {
        if ($item['status'] === 'pending_review' && $item['first_seen'] === date('Y-m-d')) {
            log_line('  - [' . $item['id'] . '] ' . $item['title'], $logFile);
        }
    }
}

log_line('Fatto.', $logFile);
