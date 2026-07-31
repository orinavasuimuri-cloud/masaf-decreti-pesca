<?php
require_once __DIR__ . '/../lib/bandi_store.php';

$now = '2026-07-30T09:00:00+02:00';
$mk = static fn(string $id, array $extra = []): array => array_merge([
    'id' => $id, 'wp_id' => 0, 'origine' => 'aggregatore', 'regioni' => ['toscana'],
    'priorita' => 'P1', 'titolo' => "t$id", 'scopo' => '', 'codice_intervento' => '',
    'pubblicazione' => '2026-06-01', 'scadenza' => '2026-08-01', 'terminato_in_fonte' => false,
    'nota' => '', 'url_fonte' => "https://x.it/$id", 'url_ufficiale' => null,
    'dettagli_mancanti' => false,
], $extra);

// --- load su file assente ---
$empty = bandi_store_load(__DIR__ . '/fixtures/non-esiste.json');
t_eq($empty['items'], [], 'load: items vuoto se il file manca');
t_true(isset($empty['_meta']['fonti']), 'load: _meta.fonti presente');
t_true(isset($empty['_meta']['copertura']), 'load: _meta.copertura presente');

// --- load su file presente ma corrotto: eccezione, non degrado silenzioso a vuoto ---
// File assente e file corrotto sono fatti diversi: solo il primo è "prima
// installazione". Il secondo è una scrittura parziale (disco pieno,
// interruzione) e degradare a vuoto qui farebbe perdere l'intero archivio al
// salvataggio successivo.
$troncato = sys_get_temp_dir() . '/bandi_test_troncato_' . getmypid() . '.json';
file_put_contents($troncato, '{"_meta":{"fonti":{}},"items":[{"id":"a"');
$caughtTroncato = false;
try { bandi_store_load($troncato); } catch (RuntimeException) { $caughtTroncato = true; }
t_true($caughtTroncato, 'load: JSON troncato solleva RuntimeException');
unlink($troncato);

$senzaItems = sys_get_temp_dir() . '/bandi_test_senza_items_' . getmypid() . '.json';
file_put_contents($senzaItems, '{"_meta":{"fonti":{}}}');
$caughtSenzaItems = false;
try { bandi_store_load($senzaItems); } catch (RuntimeException) { $caughtSenzaItems = true; }
t_true($caughtSenzaItems, 'load: JSON valido ma senza "items" solleva RuntimeException');
unlink($senzaItems);

// --- merge aggiunge ---
$s = bandi_store_merge($empty, 'aggregatore', [$mk('a'), $mk('b')], $now);
t_eq(count($s['items']), 2, 'merge: due voci aggiunte');
t_eq($s['_meta']['fonti']['aggregatore']['consecutive_failures'], 0, 'merge: fallimenti azzerati');
t_eq($s['_meta']['fonti']['aggregatore']['last_ok'], $now, 'merge: last_ok registrato');

// --- merge non duplica ---
$s2 = bandi_store_merge($s, 'aggregatore', [$mk('a')], $now);
t_eq(count($s2['items']), 2, 'merge: id già presente non duplica');

// --- merge aggiorna i campi che possono cambiare ---
$s3 = bandi_store_merge($s2, 'aggregatore', [
    $mk('a', ['titolo' => 'Titolo corretto', 'scadenza' => '2026-09-15',
              'nota' => 'Bando prorogato', 'terminato_in_fonte' => true,
              'url_ufficiale' => 'https://regione.toscana.it/atto']),
], $now);
$a = null;
foreach ($s3['items'] as $it) { if ($it['id'] === 'a') { $a = $it; } }
t_eq($a['titolo'], 'Titolo corretto', 'merge: titolo aggiornato');
t_eq($a['scadenza'], '2026-09-15', 'merge: proroga recepita');
t_eq($a['nota'], 'Bando prorogato', 'merge: nota aggiornata');
t_true($a['terminato_in_fonte'], 'merge: stato in fonte aggiornato');
t_eq($a['url_ufficiale'], 'https://regione.toscana.it/atto', 'merge: link ufficiale aggiunto');
t_eq($a['pubblicazione'], '2026-06-01', 'merge: data di pubblicazione invariata');

// --- merge non azzera campi buoni quando la fonte degrada (markup cambiato) ---
// Scenario reale: la fonte cambia il markup dello scopo (è un tema Divi, capita).
// Il run successivo trova scopo e codice_intervento vuoti e, per conseguenza,
// bandi_parse_archivio() fa ricadere il titolo sulla priorità (etichetta
// generica, uguale per decine di bandi). Senza protezione questo azzererebbe
// dati buoni anche su voci chiuse da anni, che nessuno ricontrollerà mai.
$buona = bandi_store_merge($empty, 'aggregatore', [$mk('buona', [
    'priorita' => 'Priorità 3 - Ammodernamento della flotta',
    'titolo' => 'Sostegno agli investimenti a bordo dei pescherecci',
    'scopo' => 'Favorire l\'ammodernamento della flotta peschereccia',
    'codice_intervento' => '221502',
    'regioni' => ['sicilia'],
])], $now);
$degradata = bandi_store_merge($buona, 'aggregatore', [$mk('buona', [
    // Stesso markup rotto simulato di bandi_parse_archivio(): scopo e codice
    // vuoti, titolo ricaduto sulla priorità, dettagli_mancanti a true.
    'priorita' => 'Priorità 3 - Ammodernamento della flotta',
    'titolo' => 'Priorità 3 - Ammodernamento della flotta',
    'scopo' => '',
    'codice_intervento' => '',
    'regioni' => [],
    'dettagli_mancanti' => true,
])], $now);
$b = null;
foreach ($degradata['items'] as $it) { if ($it['id'] === 'buona') { $b = $it; } }
t_eq($b['titolo'], 'Sostegno agli investimenti a bordo dei pescherecci',
    'merge: titolo buono conservato quando la fonte degrada e ricade sulla priorità');
t_eq($b['scopo'], 'Favorire l\'ammodernamento della flotta peschereccia',
    'merge: scopo buono conservato quando la fonte lo restituisce vuoto');
t_eq($b['codice_intervento'], '221502',
    'merge: codice di intervento buono conservato quando la fonte lo restituisce vuoto');
t_eq($b['regioni'], ['sicilia'], 'merge: regioni buone conservate quando la fonte non le classifica più');
t_eq($b['priorita'], 'Priorità 3 - Ammodernamento della flotta', 'merge: priorità aggiornata normalmente (non è mai vuota)');

// --- merge: un valore realmente nuovo passa comunque, anche con dettagli_mancanti ---
$conProroga = bandi_store_merge($buona, 'aggregatore', [$mk('buona', [
    'priorita' => 'Priorità 3 - Ammodernamento della flotta',
    'titolo' => 'Priorità 3 - Ammodernamento della flotta',
    'scopo' => '',
    'codice_intervento' => '',
    'scadenza' => '2026-12-31',
    'dettagli_mancanti' => true,
])], $now);
$c = null;
foreach ($conProroga['items'] as $it) { if ($it['id'] === 'buona') { $c = $it; } }
t_eq($c['scadenza'], '2026-12-31', 'merge: un valore nuovo e non vuoto passa comunque, anche a fonte degradata');

// --- merge conserva le voci di altre fonti ---
$s4 = bandi_store_merge($s3, 'basilicata-rss', [$mk('c', ['origine' => 'istituzionale'])], $now);
t_eq(count($s4['items']), 3, 'merge: fonte diversa si somma');
$s5 = bandi_store_merge($s4, 'basilicata-rss', [], $now);
t_eq(count($s5['items']), 3, 'merge: fonte senza novità non cancella nulla');

// --- nessuna potatura: l'archivio dei chiusi si conserva ---
$vecchio = bandi_store_merge($empty, 'aggregatore', [
    $mk('storico', ['pubblicazione' => '2022-01-10', 'scadenza' => '2022-03-01']),
], $now);
t_eq(count($vecchio['items']), 1, 'nessuna potatura: la voce del 2022 resta');
t_true(!function_exists('bandi_store_prune'), 'nessuna funzione di potatura definita');

// --- fallimento non tocca gli items ---
$f = bandi_store_mark_failure($s5, 'aggregatore', 'HTTP 503', $now);
t_eq(count($f['items']), 3, 'failure: gli items restano');
t_eq($f['_meta']['fonti']['aggregatore']['consecutive_failures'], 1, 'failure: contatore a 1');
t_eq($f['_meta']['fonti']['aggregatore']['last_error'], 'HTTP 503', 'failure: errore registrato');
$f2 = bandi_store_mark_failure($f, 'aggregatore', 'HTTP 503', $now);
t_eq($f2['_meta']['fonti']['aggregatore']['consecutive_failures'], 2, 'failure: contatore incrementa');
$rec = bandi_store_merge($f2, 'aggregatore', [$mk('d')], $now);
t_eq($rec['_meta']['fonti']['aggregatore']['consecutive_failures'], 0, 'recupero: fallimenti a 0');
t_eq($rec['_meta']['fonti']['aggregatore']['last_error'], null, 'recupero: errore cancellato');

// --- staleness a 7 giorni ---
t_true(!bandi_fonte_is_stale($rec, 'aggregatore', $now), 'fonte aggiornata ora: non stale');
t_true(!bandi_fonte_is_stale($rec, 'aggregatore', '2026-08-05T09:00:00+02:00'), 'dopo 6 giorni: non stale');
t_true(bandi_fonte_is_stale($rec, 'aggregatore', '2026-08-07T10:00:00+02:00'), 'dopo 8 giorni: stale');
t_true(bandi_fonte_is_stale($rec, 'mai-vista', $now), 'fonte senza last_ok: stale');

// --- copertura ---
// $rec contiene 3 voci aggregatore (a, b, d) e 1 di un feed istituzionale (c,
// via basilicata-rss): "raccolti" deve contare solo le prime, altrimenti un
// ammanco vero nella raccolta resterebbe mascherato dalle segnalazioni dei
// feed, che l'API non censisce e che l'archivio accumula senza mai potarle.
$cop = bandi_store_set_copertura($rec, 147, ['toscana' => 18, 'sicilia' => 18], ['toscana' => 20, 'sicilia' => 18]);
$aggregatoreCount = count(array_filter($cop['items'], static fn(array $v): bool => $v['origine'] === 'aggregatore'));
t_eq($cop['_meta']['copertura']['attesi_api'], 147, 'copertura: attesi registrati');
t_eq($cop['_meta']['copertura']['raccolti'], $aggregatoreCount, 'copertura: raccolti conta solo le voci di origine aggregatore');
t_true($cop['_meta']['copertura']['raccolti'] < count($cop['items']),
    'copertura: raccolti esclude le segnalazioni dai feed istituzionali');
t_eq($cop['_meta']['copertura']['per_regione']['toscana'], 18, 'copertura: dettaglio per regione raccolta');
t_eq($cop['_meta']['copertura']['dichiarati_per_regione']['toscana'], 20,
    'copertura: dettaglio dichiarato dal censimento per categoria');

// --- save verificato ---
$tmp = sys_get_temp_dir() . '/bandi_test_' . getmypid() . '.json';
bandi_store_save($tmp, $cop);
t_true(file_exists($tmp), 'save: file creato');
$reread = bandi_store_load($tmp);
t_eq(count($reread['items']), count($cop['items']), 'save: rilettura coerente');
t_eq($reread['_meta']['copertura']['attesi_api'], 147, 'save: _meta conservato');
unlink($tmp);

$caught = false;
try {
    $bad = $cop;
    $bad['items'][0]['titolo'] = "byte non validi \xFF\xFE";
    bandi_store_save($tmp, $bad);
} catch (RuntimeException) {
    $caught = true;
}
t_true($caught, 'save: json_encode fallito solleva eccezione invece di scrivere');
t_true(!file_exists($tmp), 'save: nessun file scritto in caso di errore');
t_true(!file_exists($tmp . '.tmp'), 'save: nessun file temporaneo residuo dopo un errore di encoding');

// --- save: scrittura atomica, nessun .tmp residuo dopo un salvataggio riuscito ---
$tmpAtomico = sys_get_temp_dir() . '/bandi_test_atomico_' . getmypid() . '.json';
bandi_store_save($tmpAtomico, $cop);
t_true(file_exists($tmpAtomico), 'save: file finale creato via rename()');
t_true(!file_exists($tmpAtomico . '.tmp'), 'save: nessun file temporaneo residuo dopo il successo');

// --- save: un archivio già scritto resta intatto se il salvataggio successivo fallisce ---
// Simula il caso reale del punto critico: una scrittura che non arriva in fondo
// (qui, un errore di json_encode) non deve mai lasciare un file finale troncato
// o comunque diverso da quello buono precedente.
$contenutoPrima = file_get_contents($tmpAtomico);
$badSuVecchio = $cop;
$badSuVecchio['items'][0]['titolo'] = "byte non validi \xFF\xFE";
$caughtPreserve = false;
try {
    bandi_store_save($tmpAtomico, $badSuVecchio);
} catch (RuntimeException) {
    $caughtPreserve = true;
}
t_true($caughtPreserve, 'save: salvataggio fallito su un archivio esistente solleva eccezione');
t_eq(file_get_contents($tmpAtomico), $contenutoPrima,
    'save: archivio precedente intatto dopo un salvataggio fallito (nessuna perdita silenziosa)');
unlink($tmpAtomico);
