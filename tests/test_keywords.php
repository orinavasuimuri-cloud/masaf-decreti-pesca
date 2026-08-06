<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/keywords.php';

// --- il filtro comune alle tre fonti ---
// Notizie, bandi e Gazzetta avevano tre copie di questo codice, e il bug del
// delimitatore mancante in preg_quote() e' stato corretto tre volte perche' tre
// volte era stato scritto. I controlli stanno qui una volta sola.

t_eq(keywords_pattern([]), '', 'keywords: senza parole chiave il pattern deve essere vuoto');
t_true(keywords_corrisponde('', 'un titolo qualunque'), 'keywords: un pattern vuoto deve lasciar passare tutto');

$p = keywords_pattern(['pesca', 'acquacoltura']);
t_true(keywords_corrisponde($p, 'Decreto sulla pesca a strascico'), 'keywords: una parola presente non viene riconosciuta');
t_true(keywords_corrisponde($p, 'PESCA in maiuscolo'), 'keywords: il confronto deve ignorare le maiuscole');
t_eq(keywords_corrisponde($p, 'Decreto sui pascoli montani'), false, 'keywords: un testo fuori tema non deve corrispondere');

// Il bug vero: una parola chiave che contiene il delimitatore. Senza il secondo
// argomento di preg_quote() l'espressione esce malformata, preg_match torna
// false e il chiamante lo legge come "nessuna corrispondenza": ogni voce
// scartata in silenzio, e la fonte che sembra solo povera di notizie.
$pBarra = keywords_pattern(['acqua/mare', 'pesca']);
t_true(keywords_corrisponde($pBarra, 'documento su acqua/mare'), 'keywords: una parola con la barra deve funzionare, non rompere il pattern');
t_true(keywords_corrisponde($pBarra, 'nota sulla pesca'), 'keywords: le altre parole devono restare valide accanto a una con la barra');
t_eq(keywords_corrisponde($pBarra, 'testo senza attinenza'), false, 'keywords: con la barra il filtro deve comunque escludere il fuori tema');

// Altri metacaratteri che arrivano dalla configurazione e non dal codice.
$pMeta = keywords_pattern(['art. 1(2)', 'D.M.']);
t_true(keywords_corrisponde($pMeta, 'vedi art. 1(2) del regolamento'), 'keywords: le parentesi vanno trattate come testo, non come gruppo');
t_eq(keywords_corrisponde($pMeta, 'vedi art. 132 del regolamento'), false, 'keywords: il punto non deve valere come "un carattere qualunque"');

// Un elenco che non puo' produrre un'espressione valida deve fermare la
// raccolta: e' l'unico modo per distinguere "filtro rotto" da "niente in tema".
$rotto = false;
try {
    keywords_pattern(["\xC3\x28"]);
} catch (RuntimeException) {
    $rotto = true;
}
t_true($rotto, 'keywords: un elenco che produce un pattern non valido deve lanciare RuntimeException');

// La validazione deve avvenire alla costruzione, non al primo confronto: chi
// costruisce il pattern fuori dal ciclo deve sapere subito se e' rotto.
$validatoSubito = false;
try {
    keywords_pattern(["\xC3\x28"]);
} catch (RuntimeException $e) {
    $validatoSubito = str_contains($e->getMessage(), 'parole chiave');
}
t_true($validatoSubito, 'keywords: il messaggio deve dire che il guasto sta nelle parole chiave');
