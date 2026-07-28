<?php
require_once __DIR__ . '/../lib/news_store.php';

$now = '2026-07-28T12:00:00+02:00';
$mk = static fn(string $id, string $date): array => [
    'id' => $id, 'source' => 's1', 'title' => "t$id",
    'url' => "https://x.it/$id", 'date' => $date, 'summary' => '',
];

// --- load su file assente ---
$empty = news_store_load(__DIR__ . '/fixtures/non-esiste.json');
t_eq($empty['items'], [], 'load: items vuoto se il file manca');
t_true(isset($empty['_meta']['sources']), 'load: _meta presente');

// --- merge aggiunge ---
$s = news_store_merge($empty, 's1', [$mk('a', $now), $mk('b', $now)], $now);
t_eq(count($s['items']), 2, 'merge: due voci aggiunte');
t_eq($s['_meta']['sources']['s1']['consecutive_failures'], 0, 'merge: fallimenti azzerati');
t_eq($s['_meta']['sources']['s1']['last_ok'], $now, 'merge: last_ok registrato');

// --- merge non duplica ---
$s2 = news_store_merge($s, 's1', [$mk('a', $now)], '2026-07-28T18:00:00+02:00');
t_eq(count($s2['items']), 2, 'merge: id già presente non duplica');

// --- merge conserva le voci di altre fonti ---
$s3 = news_store_merge($s2, 's2', [$mk('c', $now)], $now);
t_eq(count($s3['items']), 3, 'merge: fonte diversa si somma');
$s4 = news_store_merge($s3, 's2', [], $now);
t_eq(count($s4['items']), 3, 'merge: fonte senza novità non cancella nulla');

// --- fallimento non tocca gli items ---
$s5 = news_store_mark_failure($s4, 's1', 'HTTP 403', $now);
t_eq(count($s5['items']), 3, 'failure: gli items restano');
t_eq($s5['_meta']['sources']['s1']['consecutive_failures'], 1, 'failure: contatore a 1');
t_eq($s5['_meta']['sources']['s1']['last_error'], 'HTTP 403', 'failure: errore registrato');
$s6 = news_store_mark_failure($s5, 's1', 'HTTP 403', $now);
t_eq($s6['_meta']['sources']['s1']['consecutive_failures'], 2, 'failure: contatore incrementa');

// --- staleness ---
t_true(!news_source_is_stale($s6, 's2', $now), 's2 aggiornata ora: non stale');
t_true(news_source_is_stale($s6, 's2', '2026-07-31T12:00:00+02:00'), 'dopo 72h: stale');
t_true(news_source_is_stale($s6, 'mai-vista', $now), 'fonte senza last_ok: stale');

// --- prune per numero ---
$many = $empty;
for ($i = 0; $i < 150; $i++) {
    $many = news_store_merge($many, 's1', [$mk("id$i", $now)], $now);
}
$pruned = news_store_prune($many, $now, 120, 90);
t_eq(count($pruned['items']), 120, 'prune: tetto di 120 voci');

// --- prune per età ---
$aged = news_store_merge($empty, 's1', [
    $mk('vecchia', '2026-01-01T00:00:00+01:00'),
    $mk('nuova', $now),
], $now);
$pruned2 = news_store_prune($aged, $now, 120, 90);
t_eq(count($pruned2['items']), 1, 'prune: scartata la voce oltre 90 giorni');
t_eq($pruned2['items'][0]['id'], 'nuova', 'prune: conservata la recente');

// --- ordinamento ---
$ord = news_store_merge($empty, 's1', [
    $mk('vecchia', '2026-07-01T00:00:00+02:00'),
    $mk('recente', '2026-07-27T00:00:00+02:00'),
], $now);
$ord = news_store_prune($ord, $now, 120, 90);
t_eq($ord['items'][0]['id'], 'recente', 'prune: ordinamento cronologico discendente');

// --- save verificato ---
$tmp = sys_get_temp_dir() . '/news_test_' . getmypid() . '.json';
news_store_save($tmp, $ord);
t_true(file_exists($tmp), 'save: file creato');
$reread = news_store_load($tmp);
t_eq(count($reread['items']), 2, 'save: rilettura coerente');
unlink($tmp);

$caught = false;
try {
    $bad = $ord;
    $bad['items'][0]['title'] = "byte non validi \xFF\xFE";
    news_store_save($tmp, $bad);
} catch (RuntimeException) {
    $caught = true;
}
t_true($caught, 'save: json_encode fallito solleva eccezione invece di scrivere');
t_true(!file_exists($tmp), 'save: nessun file scritto in caso di errore');
