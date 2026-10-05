<?php
declare(strict_types=1);

/**
 * Avvia manualmente uno dei programmi di raccolta, invocato dal bottone
 * "Aggiorna ora" di index.php/bandi.php/news.php. Oltre al Task Scheduler,
 * questa è l'unica altra strada per farli partire. La mappa 'pagina' -> script
 * è in lib/aggiorna_config.php, separata da qui apposta perché sia
 * verificabile da sola (tests/test_aggiorna_config.php).
 *
 * Da qui in poi il web server DEVE poter scrivere in data/, cosa che
 * docs/DEPLOY.md dichiara non necessaria in generale: chi pubblica il sito con
 * questo bottone attivo accetta quel compromesso, descritto lì.
 */
require_once __DIR__ . '/lib/lancia_fetcher.php';
require_once __DIR__ . '/lib/aggiorna_config.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Metodo non consentito: usa il bottone \"Aggiorna ora\" in pagina.\n");
}

$pagina    = (string) ($_POST['pagina'] ?? '');
$script    = aggiorna_fetcher_per_pagina($pagina);
$redirect  = aggiorna_redirect_per_pagina($pagina);

if ($script === null || $redirect === null) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Pagina sconosciuta: $pagina\n");
}

foreach ($script as $s) {
    lancia_fetcher(__DIR__ . '/' . $s);
}

header('Location: ' . $redirect . '?aggiornato=' . urlencode($pagina));
exit;
