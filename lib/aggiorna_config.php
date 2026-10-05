<?php
declare(strict_types=1);

/**
 * Cosa avviare per ciascuna pagina, quando si preme "Aggiorna ora". Lista
 * chiusa apposta, separata da aggiorna.php perché possa essere verificata da
 * sola: 'pagina' arriva da un form raggiungibile da chiunque visiti il sito,
 * e deve poter scegliere solo fra script già noti, mai un percorso costruito
 * dall'input — altrimenti diventerebbe un modo per far eseguire al server lo
 * script che si vuole.
 *
 * @return list<string>|null Nomi di file dentro la cartella del progetto, null
 *     se 'pagina' non è una delle chiavi note.
 */
function aggiorna_fetcher_per_pagina(string $pagina): ?array
{
    $mappa = [
        'decreti' => ['scraper.php', 'gazzetta_fetcher.php'],
        'bandi'   => ['bandi_fetcher.php'],
        'notizie' => ['news_fetcher.php'],
    ];
    return $mappa[$pagina] ?? null;
}

/** null se 'pagina' non è una delle chiavi note. */
function aggiorna_redirect_per_pagina(string $pagina): ?string
{
    $mappa = [
        'decreti' => 'index.php',
        'bandi'   => 'bandi.php',
        'notizie' => 'news.php',
    ];
    return $mappa[$pagina] ?? null;
}
