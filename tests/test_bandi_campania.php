<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bandi_campania.php';

$base = 'https://agricoltura.regione.campania.it/FEAMPA';
$pagina = $base . '/FEAMPA_bandi.html';
$html = (string) file_get_contents(__DIR__ . '/fixtures/campania_bandi.html');

// --- date dei decreti ---
// "DRD n. 167 del 06.03.2026": bandi_parse_data_italiana() legge solo il mese
// scritto per esteso, quindi su questa fonte tornerebbe sempre null.
t_eq(campania_parse_data_numerica('DRD n. 167 del 06.03.2026'), '2026-03-06', 'campania: data del decreto non letta');
t_eq(campania_parse_data_numerica('DRD n. 55 del 30.01.2026'), '2026-01-30', 'campania: data con giorno alto non letta');
t_eq(campania_parse_data_numerica('14/02/2025'), '2025-02-14', 'campania: la barra deve valere come il punto');
t_eq(campania_parse_data_numerica('06-03-2026'), '2026-03-06', 'campania: il trattino deve valere come il punto');
t_eq(campania_parse_data_numerica('nessuna data qui'), null, 'campania: un testo senza data deve dare null');
// Il nome dell'allegato ("DRD_167-06-03-26.pdf") non e' una fonte di date: il
// numero del decreto si confonde con il giorno e ne uscirebbe una data
// inventata. La data si legge dalla colonna del decreto, e basta quella.
t_eq(campania_parse_data_numerica('DRD_167-06-03-26.pdf'), null, 'campania: dal nome del file non si devono ricavare date');
// Una data impossibile non va inventata: meglio nessuna data che una sbagliata,
// perche' la data ordina la voce in pagina.
t_eq(campania_parse_data_numerica('DRD n. 1 del 31.02.2026'), null, 'campania: il 31 febbraio non esiste e non va accettato');
t_eq(campania_parse_data_numerica('DRD n. 1 del 06.13.2026'), null, 'campania: un mese oltre il dodicesimo va rifiutato');

// --- lettura della tabella ---
$atti = campania_parse_atti($html, $base);
t_true(count($atti) >= 15, 'campania: troppi pochi atti letti dalla tabella');

// L'intestazione delle colonne e le righe di obiettivo specifico non sono atti.
$intestazioni = array_filter($atti, static fn(array $a): bool => strcasecmp($a['azione'], 'Azione') === 0 && strcasecmp($a['bando'], 'Bando') === 0);
t_eq($intestazioni, [], 'campania: la riga dei nomi di colonna e finita fra gli atti');
$vuoti = array_filter($atti, static fn(array $a): bool => $a['bando'] === '');
t_eq($vuoti, [], 'campania: un atto senza nome di bando non deve entrare');

// Gli allegati sono relativi ("pdf/DRD_167-06-03-26.pdf"): vanno resi assoluti,
// altrimenti in pagina il collegamento porta sul nostro sito.
$conUrl = array_values(array_filter($atti, static fn(array $a): bool => $a['url'] !== null));
t_true(count($conUrl) > 0, 'campania: nessun allegato collegato');
$relativi = array_filter($conUrl, static fn(array $a): bool => !str_starts_with((string) $a['url'], 'http'));
t_eq($relativi, [], 'campania: un allegato e rimasto con indirizzo relativo');

$conData = array_filter($atti, static fn(array $a): bool => $a['data'] !== null);
t_true(count($conData) >= 15, 'campania: troppe date di decreto non lette');

// --- raggruppamento per bando ---
// La tabella elenca atti, non bandi: approvazione, proroghe e graduatoria dello
// stesso bando stanno su righe diverse. Pubblicarne una per riga mostrerebbe lo
// stesso bando piu volte, con date diverse e nessuna giusta.
$voci = campania_bandi_voci($html, $base, $pagina);
t_true(count($voci) > 0, 'campania: nessuna voce prodotta');
t_true(count($voci) < count($atti), 'campania: le voci non sono state raggruppate, sono quante gli atti');

$titoli = array_map(static fn(array $v): string => $v['titolo'], $voci);
t_eq(count($titoli), count(array_unique($titoli)), 'campania: due voci con lo stesso titolo, il raggruppamento non ha funzionato');

$ids = array_map(static fn(array $v): string => $v['id'], $voci);
t_eq(count($ids), count(array_unique($ids)), 'campania: due voci con lo stesso id si sovrascriverebbero nell archivio');

foreach ($voci as $v) {
    t_eq($v['regioni'], ['campania'], 'campania: voce non attribuita alla Campania');
    t_eq($v['origine'], 'istituzionale', 'campania: origine sbagliata');
    t_true($v['dettagli_mancanti'], 'campania: la tabella non da la scadenza, la voce deve restare incompleta');
    t_eq($v['scadenza'], null, 'campania: nessuna scadenza puo essere dedotta da questa fonte');
    t_true($v['titolo'] !== '', 'campania: voce senza titolo');
    t_true(str_starts_with((string) $v['url_ufficiale'], 'http'), 'campania: url_ufficiale non assoluto');
}

// La data della voce e quella dell'atto piu vecchio (l'approvazione), non del
// piu recente: altrimenti ogni proroga farebbe risalire il bando in cima.
$perTitolo = [];
foreach ($voci as $v) { $perTitolo[$v['titolo']] = $v; }
$attiPerBando = [];
foreach ($atti as $a) { if ($a['data'] !== null) { $attiPerBando[$a['bando']][] = $a['data']; } }
foreach ($attiPerBando as $bando => $date) {
    if (!isset($perTitolo[$bando])) { continue; }
    sort($date);
    t_eq($perTitolo[$bando]['pubblicazione'], $date[0], "campania: la data di $bando non e quella del primo atto");
}

// --- la pagina cambia forma ---
// Una fonte che smette di essere leggibile deve fermarsi a voce alta: se
// tornasse un elenco vuoto, bandi_store_merge lascerebbe l'archivio invariato e
// nessuno si accorgerebbe che la Campania non aggiorna piu.
$rotta = false;
try {
    campania_parse_atti('<html><body><p>Pagina in manutenzione</p></body></html>', $base);
} catch (RuntimeException) {
    $rotta = true;
}
t_true($rotta, 'campania: una pagina senza tabella deve lanciare RuntimeException');

$svuotata = false;
try {
    campania_parse_atti('<html><body><table><tr><td>Azione</td><td>Bando</td><td>Descrizione</td><td>Decreto</td><td>File</td></tr></table></body></html>', $base);
} catch (RuntimeException) {
    $svuotata = true;
}
t_true($svuotata, 'campania: una tabella con la sola intestazione deve lanciare RuntimeException');
