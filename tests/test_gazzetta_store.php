<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/gazzetta_store.php';

// --- numeri saltati ---
t_eq(gazzetta_numeri_saltati(176, 178), [177], 'gazzetta: il numero saltato non viene riconosciuto');
t_eq(gazzetta_numeri_saltati(177, 178), [], 'gazzetta: due numeri consecutivi non hanno salti');
t_eq(gazzetta_numeri_saltati(178, 178), [], 'gazzetta: rileggere lo stesso numero non e un salto');
t_eq(gazzetta_numeri_saltati(null, 178), [], 'gazzetta: alla prima esecuzione non si puo parlare di salti');
// Il numero riparte da 1 ogni anno solare: un salto all'indietro a gennaio e'
// normale e segnalarlo insegnerebbe a ignorare le segnalazioni.
t_eq(gazzetta_numeri_saltati(250, 1), [], 'gazzetta: il cambio d anno non e un salto');
t_eq(gazzetta_numeri_saltati(170, 175), [171, 172, 173, 174], 'gazzetta: salto multiplo non elencato per intero');

// --- archivio vuoto e merge ---
$vuoto = gazzetta_store_empty();
t_eq($vuoto['items'], [], 'gazzetta: archivio vuoto deve avere items vuoto');

$voce = [
    'id' => '26A03900', 'serie' => 'gu-sg', 'emittente' => 'MINISTERO', 'tipo_atto' => 'DECRETO',
    'titolo' => 'MINISTERO - DECRETO 15 luglio 2026', 'oggetto' => 'Interruzione della pesca. (26A03900)',
    'url' => 'http://www.gazzettaufficiale.it/eli/id/2026/08/03/26A03900/SG',
    'numero_gu' => 178, 'data_gu' => '2026-08-03', 'destinazione' => 'registro',
    'status' => 'pending_review', 'first_seen' => '2026-08-04',
];
$s = gazzetta_store_merge($vuoto, 'gu-sg', [$voce], 178, '2026-08-03', '2026-08-04T15:00:00+02:00');
t_eq(count($s['items']), 1, 'gazzetta: la voce non e stata inserita');
t_eq($s['_meta']['serie']['gu-sg']['ultimo_numero'], 178, 'gazzetta: ultimo numero non registrato');
t_eq($s['_meta']['serie']['gu-sg']['errore'], null, 'gazzetta: un merge riuscito non deve lasciare errori');

// Lo stato assegnato a mano dal curatore non deve essere sovrascritto da un
// secondo passaggio della stessa voce: e' l'unica informazione che la fonte
// non possiede.
$s['items']['26A03900']['status'] = 'curated';
$s2 = gazzetta_store_merge($s, 'gu-sg', [$voce], 179, '2026-08-04', '2026-08-05T15:00:00+02:00');
t_eq($s2['items']['26A03900']['status'], 'curated', 'gazzetta: il merge ha sovrascritto uno stato deciso a mano');
t_eq(count($s2['items']), 1, 'gazzetta: la stessa voce e stata duplicata');

// --- fallimento di una serie ---
$sf = gazzetta_store_mark_failure($s2, 'gu-sg', 'download fallito', '2026-08-05T15:00:00+02:00');
t_eq($sf['_meta']['serie']['gu-sg']['errore'], 'download fallito', 'gazzetta: errore non registrato');
t_eq($sf['_meta']['serie']['gu-sg']['ultimo_numero'], 179, 'gazzetta: un fallimento non deve perdere l ultimo numero visto');

// --- caricamento ---
$tmp = sys_get_temp_dir() . '/gazzetta_test_' . getmypid() . '.json';
@unlink($tmp);
t_eq(gazzetta_store_load($tmp)['items'], [], 'gazzetta: un file assente deve dare archivio vuoto');
gazzetta_store_save($tmp, $s2);
t_eq(count(gazzetta_store_load($tmp)['items']), 1, 'gazzetta: la voce non sopravvive al salvataggio');
// File presente ma corrotto e' un guasto, non un archivio vuoto: degradare qui
// farebbe riscrivere l'archivio buono con il nulla al run successivo.
file_put_contents($tmp, '{"items": tronc');
$corrotto = false;
try {
    gazzetta_store_load($tmp);
} catch (RuntimeException) {
    $corrotto = true;
}
t_true($corrotto, 'gazzetta: un archivio corrotto deve lanciare RuntimeException');
@unlink($tmp);
