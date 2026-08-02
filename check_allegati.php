<?php
/**
 * Confronta gli allegati pubblicati su ogni pagina MASAF citata da
 * data/catalog.json con quelli che il catalogo registra davvero.
 *
 * Una pagina "IDPagina" e' un contenitore, non un documento: il ministero vi
 * aggiunge nel tempo elenchi di unita' autorizzate, note esplicative e decreti
 * successivi che integrano il primo. Il catalogo e' curato a mano e non se ne
 * accorge da solo, quindi senza questo controllo la divergenza si scopre a
 * occhio, mesi dopo. Non modifica nulla: decidere cosa entra nel catalogo
 * resta un atto di curatela.
 *
 * Uso:  php check_allegati.php [--json]
 * Esce con codice 1 se trova divergenze, 0 se il catalogo e' allineato.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/allegati.php';

$dataDir = __DIR__ . '/data';
$logFile = $dataDir . '/allegati.log';
$soloJson = in_array('--json', $argv, true);

function ca_log(string $msg, string $logFile, bool $muto): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if (!$muto) {
        echo $line . PHP_EOL;
    }
    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

/**
 * Stessa tecnica di fetch_url() in scraper.php: openssl non e' abilitato in
 * questa installazione di PHP, quindi le richieste https passano dal curl
 * di sistema.
 */
function ca_fetch(string $url): string
{
    $cmd = sprintf(
        'curl -s -L -A %s --max-time 25 %s',
        escapeshellarg('Mozilla/5.0 (Windows NT 10.0; Win64; x64) masaf-decreti-pesca-allegati/1.0'),
        escapeshellarg($url)
    );
    $html = shell_exec($cmd);
    return is_string($html) ? $html : '';
}

$catalog = json_decode((string) file_get_contents($dataDir . '/catalog.json'), true);
if (!is_array($catalog)) {
    fwrite(STDERR, "data/catalog.json non leggibile\n");
    exit(2);
}

$esclusiFile = $dataDir . '/allegati_esclusi.json';
$esclusi = [];
if (is_file($esclusiFile)) {
    $esclusi = json_decode((string) file_get_contents($esclusiFile), true)['urls'] ?? [];
}

$pagine = allegati_pagine_catalogo($catalog);
ca_log('Controllo allegati su ' . count($pagine) . ' pagine MASAF', $logFile, $soloJson);

$esito = [];
$pagineIncomplete = 0;
$totMancanti = 0;
$totEsclusi = 0;
$errori = 0;

foreach ($pagine as $id => $info) {
    $url = 'https://www.masaf.gov.it/flex/cm/pages/ServeBLOB.php/L/IT/IDPagina/' . $id;
    $html = ca_fetch($url);

    if ($html === '') {
        $errori++;
        ca_log("  pagina $id: NON SCARICATA", $logFile, $soloJson);
        $esito[] = ['id' => $id, 'sezione' => $info['sezione'], 'errore' => true];
        continue;
    }

    $confronto = allegati_confronta(allegati_parse($html), $info['pdf'], $esclusi);
    $mancanti = $confronto['mancanti'];
    $totEsclusi += count($confronto['esclusi']);

    if ($mancanti !== [] || $confronto['ignoti'] !== []) {
        $pagineIncomplete++;
        $totMancanti += count($mancanti);
    }

    $esito[] = [
        'id'       => $id,
        'sezione'  => $info['sezione'],
        'ref'      => $info['voci'][0]['ref'] ?? '',
        'url'      => $url,
        'mancanti' => array_map(
            static fn(string $u, array $a): array => [
                'titolo' => $a['titolo'],
                'peso'   => allegati_peso_it($a['peso']),
                'pdf'    => $u,
            ],
            array_keys($mancanti),
            array_values($mancanti)
        ),
        'ignoti'   => $confronto['ignoti'],
    ];

    // Cortesia verso il sito: le pagine sono una trentina, non c'e' fretta.
    usleep(400000);
}

if ($soloJson) {
    echo json_encode($esito, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($pagineIncomplete > 0 || $errori > 0 ? 1 : 0);
}

foreach ($esito as $r) {
    if (!empty($r['errore']) || ($r['mancanti'] === [] && $r['ignoti'] === [])) {
        continue;
    }
    echo PHP_EOL . "pagina {$r['id']} · {$r['sezione']} · {$r['ref']}" . PHP_EOL;
    echo "  {$r['url']}" . PHP_EOL;
    foreach ($r['mancanti'] as $m) {
        echo "  MANCA  {$m['titolo']}  ({$m['peso']})" . PHP_EOL;
        echo "         {$m['pdf']}" . PHP_EOL;
    }
    foreach ($r['ignoti'] as $u) {
        echo "  NON PIU' IN PAGINA  $u" . PHP_EOL;
    }
}

echo PHP_EOL;
ca_log(sprintf(
    'Pagine incomplete: %d su %d · allegati non registrati: %d · esclusi per scelta: %d · pagine non scaricate: %d',
    $pagineIncomplete,
    count($pagine),
    $totMancanti,
    $totEsclusi,
    $errori
), $logFile, false);

if ($pagineIncomplete === 0 && $errori === 0) {
    echo 'Catalogo allineato: nessun allegato da valutare.' . PHP_EOL;
}

exit($pagineIncomplete > 0 || $errori > 0 ? 1 : 0);
