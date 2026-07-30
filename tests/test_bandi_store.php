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
$cop = bandi_store_set_copertura($rec, 147, ['toscana' => 18, 'sicilia' => 18]);
t_eq($cop['_meta']['copertura']['attesi_api'], 147, 'copertura: attesi registrati');
t_eq($cop['_meta']['copertura']['raccolti'], count($cop['items']), 'copertura: raccolti = voci in archivio');
t_eq($cop['_meta']['copertura']['per_regione']['toscana'], 18, 'copertura: dettaglio per regione');

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
