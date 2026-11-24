<?php
require_once __DIR__ . '/../lib/eurlex.php';

// --- filtro: Italia, Mediterraneo o tutta la pesca UE; fuori le altre zone ---
$tema = [
    'Regolamento (UE) 2026/2473 ... che modifica il regolamento (UE) 2023/2124, relativo a talune disposizioni per la pesca nella zona CGPM' => true,
    "Regolamento di esecuzione (UE) 2026/2248 ... che applica detrazioni dallo sforzo di pesca a disposizione dell\u{2019}Italia nel 2026" => true,
    "Regolamento di esecuzione (UE) 2026/2057 ... che aggiunge ai contingenti\u{a0}di\u{a0}pesca per il 2026 alcuni quantitativi" => true,
    'Regolamento di esecuzione (UE) 2026/2085 ... che proroga la deroga al regolamento (CE) n. 1967/2006 per le sciabiche' => true,
    'Regolamento (UE) 2026/266 del Consiglio ... possibilità di pesca applicabili nel Mar Mediterraneo e nel Mar Nero' => true,
    'Regolamento delegato (UE) 2026/2034 ... modifica il regolamento (UE) 2019/1241 ... cetacei nel Golfo di Biscaglia' => false,
    'Regolamento delegato (UE) 2026/450 ... 2019/1241 ... focene del Baltico centrale' => false,
    'Regolamento (UE) 2026/1933 del Consiglio ... possibilità di pesca ... Repubblica delle Seychelles' => false,
    'Regolamento (UE) 2026/2501 ... modifica dell’allegato XVII del regolamento (CE) n. 1907/2006 (REACH)' => false,
    'Regolamento di esecuzione (UE) 2026/2228 ... ingresso nell’Unione di partite di determinati animali' => false,
    'Decisione ... posizione ... CGPM e accordo con il Marocco nel Mediterraneo' => true,
    "... che aggiunge ai contingenti di pesca per il 2026 ... a norma dell\u{2019}articolo 4 del regolamento (CE) n. 847/96" => true,
    'Regolamento ... possibilità di pesca nel Mar Artico per il merluzzo' => false,
];
foreach ($tema as $titolo => $atteso) {
    t_eq(eurlex_in_tema($titolo), $atteso, 'eurlex filtro: ' . substr($titolo, 0, 60));
}

// --- lettura della risposta CSV ---
$csv = "\"celex\",\"data\",\"titolo\"\n\"32026R2473\",2026-10-21,\"Regolamento (UE) 2026/2473, che modifica il regolamento (UE) 2023/2124 (CGPM)\"\n\"bad\",non-data,\"x\"\n";
$r = eurlex_parse_csv($csv);
t_eq(count($r), 1, 'eurlex csv: righe valide lette, quelle rotte scartate');
t_eq($r[0]['celex'], '32026R2473', 'eurlex csv: celex');
$errore = false;
try { eurlex_parse_csv('<html>Service unavailable</html>'); } catch (RuntimeException) { $errore = true; }
t_true($errore, 'eurlex csv: una risposta che non è il CSV atteso è un errore, non zero atti');

// --- unione: i nuovi da rivedere, le decisioni già prese restano ---
$s = ['_meta' => [], 'items' => []];
$s = eurlex_merge($s, $r, '2026-11-24');
t_eq($s['items']['32026R2473']['status'], 'pending_review', 'eurlex merge: nuovo atto da rivedere');
t_eq($s['items']['32026R2473']['url'], 'https://eur-lex.europa.eu/legal-content/IT/TXT/?uri=CELEX:32026R2473', 'eurlex merge: link EUR-Lex italiano');
$s['items']['32026R2473']['status'] = 'excluded';
$s = eurlex_merge($s, $r, '2026-11-25');
t_eq($s['items']['32026R2473']['status'], 'excluded', 'eurlex merge: un atto escluso non torna da rivedere');
t_eq($s['items']['32026R2473']['first_seen'], '2026-11-24', 'eurlex merge: la data di scoperta non cambia');
t_eq(count(eurlex_da_rivedere($s['items'])), 0, 'eurlex: la coda non contiene gli esclusi');
