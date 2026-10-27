<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/aggiorna_config.php';

// --- il bottone è unico: da ogni pagina parte lo stesso aggiornamento completo ---

foreach (['decreti', 'bandi', 'notizie'] as $pagina) {
    t_eq(aggiorna_fetcher_per_pagina($pagina), ['aggiorna_tutto.php'],
        "aggiorna_config: da '$pagina' parte l'aggiornamento di tutte le pagine");
}

t_eq(aggiorna_redirect_per_pagina('decreti'), 'index.php', 'aggiorna_config: decreti reindirizza a index.php');
t_eq(aggiorna_redirect_per_pagina('bandi'), 'bandi.php', 'aggiorna_config: bandi reindirizza a bandi.php');
t_eq(aggiorna_redirect_per_pagina('notizie'), 'news.php', 'aggiorna_config: notizie reindirizza a news.php');

// Ogni pagina deve ricevere dati nuovi: se un fetcher sparisse dalla lista,
// la sua pagina smetterebbe di aggiornarsi dal bottone senza alcun errore.
$tutti = aggiorna_tutti_i_fetcher();
foreach (['scraper.php', 'gazzetta_fetcher.php', 'news_fetcher.php', 'bandi_fetcher.php'] as $s) {
    t_true(in_array($s, $tutti, true), "aggiorna_config: $s manca dall'aggiornamento completo");
    t_true(is_file(__DIR__ . '/../' . $s), "aggiorna_config: $s non esiste nella cartella del progetto");
}
t_eq(end($tutti), 'bandi_fetcher.php', 'aggiorna_config: i bandi, i più lenti, vanno per ultimi');

// --- input sconosciuto o ostile torna null, mai uno script a caso ---
// aggiorna.php tratta null come "pagina sconosciuta, 400": se qui tornasse
// qualcosa invece di null, quel qualcosa verrebbe passato a lancia_fetcher() e
// il form potrebbe far eseguire al server un file a sua scelta.

foreach (['', 'qualcosa', 'Decreti', 'decreti ', '../scraper', 'scraper.php', '0'] as $input) {
    t_eq(aggiorna_fetcher_per_pagina($input), null, "aggiorna_config: '$input' non deve mappare a nessuno script");
    t_eq(aggiorna_redirect_per_pagina($input), null, "aggiorna_config: '$input' non deve mappare a nessun redirect");
}
