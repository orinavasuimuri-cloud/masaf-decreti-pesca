<?php
declare(strict_types=1);

/**
 * Esegue tutti i programmi di raccolta uno dopo l'altro. Lo avvia il bottone
 * "Aggiorna" (aggiorna.php, tramite lib/lancia_fetcher.php) come unico processo
 * staccato: lanciarli tutti insieme li metterebbe in coda sul lock comune
 * (lib/lock.php), e chi aspetta oltre LOCK_ATTESA_FETCHER rinuncia - con
 * bandi_fetcher.php che da solo può durare più di quell'attesa.
 *
 * I veloci prima, così notizie e decreti si aggiornano entro pochi minuti.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/lib/lancia_fetcher.php';
require_once __DIR__ . '/lib/aggiorna_config.php';

$php = escapeshellarg(php_cli_binary());
foreach (aggiorna_tutti_i_fetcher() as $script) {
    exec('cd ' . escapeshellarg(__DIR__) . " && $php " . escapeshellarg(__DIR__ . '/' . $script) . ' 2>&1', $output, $codice);
}
