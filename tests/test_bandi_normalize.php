<?php
require_once __DIR__ . '/../lib/bandi_normalize.php';

// --- bandi_testo: il nbsp è il punto critico ---
// Nell'archivio i valori arrivano attaccati all'etichetta con un U+00A0:
// senza normalizzarlo "15 Luglio 2026" resta "\u{A0}15 Luglio 2026" e la data non si legge.
t_eq(bandi_testo("\u{A0}15 Luglio 2026"), '15 Luglio 2026', 'nbsp iniziale rimosso');
t_eq(bandi_testo('<p>Ciao   <b>mondo</b></p>'), 'Ciao mondo', 'tag via, spazi compattati');
t_eq(bandi_testo('Pesca &amp; acquacoltura'), 'Pesca & acquacoltura', 'entità decodificate');
t_eq(bandi_testo('attività&#8217;'), 'attività' . "\u{2019}", 'entità numeriche decodificate');
t_eq(bandi_testo('   '), '', 'solo spazi diventa stringa vuota');

// --- bandi_parse_data_italiana ---
t_eq(bandi_parse_data_italiana('15 Luglio 2026'), '2026-07-15', 'data italiana standard');
t_eq(bandi_parse_data_italiana('10 Giugno 2026'), '2026-06-10', 'mese con maiuscola');
t_eq(bandi_parse_data_italiana('1 gennaio 2027'), '2027-01-01', 'giorno a una cifra, mese minuscolo');
t_eq(bandi_parse_data_italiana("\u{A0} 31 Dicembre 2026 "), '2026-12-31', 'nbsp e spazi ignorati');
t_eq(bandi_parse_data_italiana(''), null, 'stringa vuota');
t_eq(bandi_parse_data_italiana('prossimamente'), null, 'testo libero');
t_eq(bandi_parse_data_italiana('32 Luglio 2026'), null, 'giorno inesistente');
t_eq(bandi_parse_data_italiana('15 Luglione 2026'), null, 'mese inesistente');

// --- bandi_stato ---
$oggi = '2026-07-30';
t_eq(bandi_stato('2026-08-15', false, $oggi), 'aperto', 'scadenza futura');
t_eq(bandi_stato('2026-07-30', false, $oggi), 'aperto', 'scadenza oggi: ancora aperto');
t_eq(bandi_stato('2026-07-29', false, $oggi), 'chiuso', 'scadenza passata');
// La scadenza vince sulla categoria: la fonte marca "Terminato" con giorni di ritardo.
t_eq(bandi_stato('2026-08-15', true, $oggi), 'aperto', 'scadenza futura anche se marcato terminato');
t_eq(bandi_stato(null, true, $oggi), 'chiuso', 'senza scadenza, marcato terminato');
t_eq(bandi_stato(null, false, $oggi), 'da_verificare', 'senza scadenza, non marcato');
t_eq(bandi_stato('', false, $oggi), 'da_verificare', 'scadenza vuota equivale ad assente');

// --- bandi_titolo_da_scopo ---
$scopo = "L\u{2019}azione \u{00AB}Salute e compatibilità ambientale dei prodotti dell\u{2019}acquacoltura\u{00BB} è " .
       "finalizzata a promuovere un\u{2019}acquacoltura in grado di soddisfare rigorose condizioni.";
t_eq(
    bandi_titolo_da_scopo($scopo),
    "Salute e compatibilità ambientale dei prodotti dell\u{2019}acquacoltura",
    'il nome dell\'azione fra virgolette diventa il titolo'
);
// Test con virgolette curve (stile WordPress) - ADDED per coprire il bug
$scopo_curve = "L\u{2019}azione \u{201C}Salute e sicurezza a bordo\u{201D} è finalizzata a sostenere gli investimenti.";
t_eq(
    bandi_titolo_da_scopo($scopo_curve),
    'Salute e sicurezza a bordo',
    'il nome dell\'azione fra virgolette curve diventa il titolo'
);
// Test con virgolette dritte (testo semplice e feed)
$scopo_dritte = "L'azione \"Benessere dell'acquacoltura biologica\" è finalizzata a sostenere metodi sostenibili.";
t_eq(
    bandi_titolo_da_scopo($scopo_dritte),
    "Benessere dell'acquacoltura biologica",
    'il nome dell\'azione fra virgolette dritte diventa il titolo'
);
$senzaVirgolette = 'Sostegno agli investimenti a bordo dei pescherecci per migliorare la sicurezza ' .
                 'e le condizioni di lavoro, con particolare riguardo alla flotta artigianale.';
$t = bandi_titolo_da_scopo($senzaVirgolette, 90);
t_true(strlen($t) <= 94, 'senza virgolette: troncato entro il limite');
t_true(str_ends_with($t, '…'), 'senza virgolette: ellissi finale');
t_true(str_starts_with($t, 'Sostegno agli investimenti'), 'senza virgolette: inizio conservato');
t_eq(bandi_titolo_da_scopo('Contributo breve.'), 'Contributo breve.', 'testo corto invariato');
t_eq(bandi_titolo_da_scopo(''), '', 'scopo vuoto');
// Test con stringa accentata sotto limite caratteri ma sopra limite byte - ADDED per coprire bug strlen()
$accentato = "Sostenimento dell\u{2019}attività di pesca e dell\u{2019}acquacoltura, dell\u{2019}ambiente, dell\u{2019}economia";
$num_chars = preg_match_all('/./u', $accentato);
$num_bytes = strlen($accentato);
t_true($num_chars <= 90, 'stringa accentata: caratteri entro limite');
t_true($num_bytes > 90, 'stringa accentata: byte sopra limite');
t_eq(bandi_titolo_da_scopo($accentato, 90), $accentato, 'stringa accentata sotto limite caratteri non viene troncata');

// --- bandi_regioni_da_classi ---
$cls = 'et_pb_post post-1376 post type-post status-publish hentry category-terminato category-toscana';
t_eq(bandi_regioni_da_classi($cls), ['toscana'], 'terminato escluso, resta la regione');
t_true(bandi_e_terminato($cls), 'categoria terminato riconosciuta');
$due = 'et_pb_post post-9 hentry category-puglia category-basilicata';
t_eq(bandi_regioni_da_classi($due), ['puglia', 'basilicata'], 'due regioni conservate entrambe');
t_true(!bandi_e_terminato($due), 'senza categoria terminato');
t_eq(bandi_regioni_da_classi('et_pb_post post-3 hentry category-senza-categoria'), [],
     'senza-categoria non è una regione');
t_eq(bandi_regioni_da_classi('et_pb_post post-4 hentry category-bandi-masaf-nazionali'),
     ['bandi-masaf-nazionali'], 'la categoria nazionale passa: la pagina la tratta a parte');
t_eq(bandi_regioni_da_classi('et_pb_post post-5 hentry'), [], 'nessuna categoria');
