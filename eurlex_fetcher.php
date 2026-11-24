<?php
declare(strict_types=1);

// Programma da riga di comando: dal browser non deve partire (vedi aggiorna_tutto.php).
if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit;
}

/**
 * Atti UE sulla pesca da EUR-Lex (vedi lib/eurlex.php). Di solito non serve
 * lanciarlo: gira in coda a gazzetta_fetcher.php. Resta per il giro a mano.
 *
 * Uso: php eurlex_fetcher.php
 */
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/lib/lock.php';
require_once __DIR__ . '/lib/eurlex.php';

$dataDir = __DIR__ . '/data';
$log = static function (string $m) use ($dataDir): void {
    $riga = '[' . date('Y-m-d H:i:s') . '] ' . $m;
    echo $riga . PHP_EOL;
    file_put_contents("$dataDir/gazzetta.log", $riga . PHP_EOL, FILE_APPEND);
};
lock_o_esci("$dataDir/.fetch.lock", $log);
try {
    eurlex_aggiorna($dataDir, $log);
} catch (Throwable $e) {
    $log('ERRORE EUR-Lex: ' . $e->getMessage());
    exit(1);
}
