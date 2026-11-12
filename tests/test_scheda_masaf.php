<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scheda_masaf.php';

// --- titolo dalla scheda: il caso reale del D.D. 614745, pagina in ISO-8859-1 ---
$html = '<html><head><meta property="og:title" content="D.D. n. 614745 del 09/11/2026 - Disposizioni in materia di interruzione delle attivit' . "\xE0" . ' di pesca del Gambero rosso mediterraneo" /></head></html>';
t_eq(
    scheda_masaf_titolo($html),
    'D.D. n. 614745 del 09/11/2026 - Disposizioni in materia di interruzione delle attività di pesca del Gambero rosso mediterraneo',
    'scheda_masaf: og:title letto e convertito da ISO-8859-1'
);
t_eq(
    scheda_masaf_titolo('<meta property="og:title" content="Masaf - D.D. n. 1 del 01/01/2026 - attivit&agrave; prova">'),
    'D.D. n. 1 del 01/01/2026 - attività prova',
    'scheda_masaf: prefisso "Masaf -" tolto ed entità decodificate'
);
t_eq(scheda_masaf_titolo('<html><title>x</title></html>'), null, 'scheda_masaf: senza og:title torna null');

// --- estremi dell'atto ---
t_eq(scheda_masaf_ref('D.D. n. 614745 del 09/11/2026 - Disposizioni'), 'D.D. n. 614745 · 09/11/2026', 'scheda_masaf: decreto direttoriale');
t_eq(scheda_masaf_ref('D.M. n. 239451 del 19/05/2026 - Arresto'), 'D.M. n. 239451 · 19/05/2026', 'scheda_masaf: decreto ministeriale');
t_eq(scheda_masaf_ref('Decreto Direttoriale prot. n. 585523 del 26/10/2026'), 'D.D. n. 585523 · 26/10/2026', 'scheda_masaf: forma estesa con prot.');
t_eq(scheda_masaf_ref('D.D. n.0322506 del 3/7/2026'), 'D.D. n. 0322506 · 03/07/2026', 'scheda_masaf: spazi e zeri mancanti');
t_eq(scheda_masaf_ref('Avviso pubblico per le imprese di pesca'), null, 'scheda_masaf: atto senza numero');
t_eq(
    scheda_masaf_ref('FEAMPA 2021/2027 Decreto Direttoriale prot. n. 585541 del 26 ottobre 2026 - Scorrimento'),
    'D.D. n. 585541 · 26/10/2026',
    'scheda_masaf: prefisso FEAMPA e mese in lettere'
);
t_eq(scheda_masaf_ref('FEAMPA 2021/2027 - Art. 21 Arresto temporaneo obbligatorio anno 2026'), null,
    'scheda_masaf: "anno 2026" non è un numero di atto');
