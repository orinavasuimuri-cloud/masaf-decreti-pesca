<?php
declare(strict_types=1);

/**
 * Il bottone "Aggiorna" è unico per tutto il sito: da qualunque pagina si
 * prema, aggiorna decreti, Gazzetta, notizie e bandi. Lista chiusa apposta,
 * separata da aggiorna.php perché possa essere verificata da sola: 'pagina'
 * arriva da un form, e deve poter scegliere solo fra valori già noti, mai un
 * percorso costruito dall'input - altrimenti diventerebbe un modo per far
 * eseguire al server lo script che si vuole.
 */

/** @return list<string> I programmi di raccolta, nell'ordine in cui eseguirli. */
function aggiorna_tutti_i_fetcher(): array
{
    return ['news_fetcher.php', 'scraper.php', 'gazzetta_fetcher.php', 'bandi_fetcher.php'];
}

/**
 * @return list<string>|null Script da avviare dalla cartella del progetto, null
 *     se 'pagina' non è una delle chiavi note.
 */
function aggiorna_fetcher_per_pagina(string $pagina): ?array
{
    return aggiorna_redirect_per_pagina($pagina) === null ? null : ['aggiorna_tutto.php'];
}

/** Pagina a cui tornare dopo il clic; null se 'pagina' non è una delle chiavi note. */
function aggiorna_redirect_per_pagina(string $pagina): ?string
{
    $mappa = [
        'decreti' => 'index.php',
        'bandi'   => 'bandi.php',
        'notizie' => 'news.php',
    ];
    return $mappa[$pagina] ?? null;
}
