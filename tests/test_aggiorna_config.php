<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/aggiorna_config.php';

// --- le tre pagine note tornano la mappa attesa ---

t_eq(aggiorna_fetcher_per_pagina('decreti'), ['scraper.php', 'gazzetta_fetcher.php'],
    'aggiorna_config: decreti lancia scraper e gazzetta, le due fonti di index.php');
t_eq(aggiorna_fetcher_per_pagina('bandi'), ['bandi_fetcher.php'],
    'aggiorna_config: bandi lancia solo bandi_fetcher');
t_eq(aggiorna_fetcher_per_pagina('notizie'), ['news_fetcher.php'],
    'aggiorna_config: notizie lancia solo news_fetcher');

t_eq(aggiorna_redirect_per_pagina('decreti'), 'index.php', 'aggiorna_config: decreti reindirizza a index.php');
t_eq(aggiorna_redirect_per_pagina('bandi'), 'bandi.php', 'aggiorna_config: bandi reindirizza a bandi.php');
t_eq(aggiorna_redirect_per_pagina('notizie'), 'news.php', 'aggiorna_config: notizie reindirizza a news.php');

// --- input sconosciuto o ostile torna null, mai uno script a caso ---
// aggiorna.php tratta null come "pagina sconosciuta, 400": se qui tornasse
// qualcosa invece di null, quel qualcosa verrebbe passato a lancia_fetcher() e
// il form pubblico potrebbe far eseguire al server un file a sua scelta.

foreach (['', 'qualcosa', 'Decreti', 'decreti ', '../scraper', 'scraper.php', '0'] as $input) {
    t_eq(aggiorna_fetcher_per_pagina($input), null, "aggiorna_config: '$input' non deve mappare a nessuno script");
    t_eq(aggiorna_redirect_per_pagina($input), null, "aggiorna_config: '$input' non deve mappare a nessun redirect");
}
