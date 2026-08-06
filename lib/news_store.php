<?php
declare(strict_types=1);

function news_store_empty(): array {
    return ['_meta' => ['last_run' => null, 'sources' => []], 'items' => []];
}

/**
 * File assente e file corrotto sono fatti diversi, come in lib/bandi_store.php
 * e lib/gazzetta_store.php: il primo è lo stato iniziale legittimo, il secondo
 * un guasto.
 *
 * Qui il corrotto degradava a "vuoto", ed era l'unico dei tre archivi a farlo:
 * il fetcher fa load, merge e save, quindi ripartiva da zero e riscriveva
 * data/news.json con le sole voci del giro corrente. Un file troncato da una
 * scrittura interrotta cancellava tutto lo storico senza una riga di log -
 * verificato: trenta voci ridotte a una, in silenzio.
 */
function news_store_load(string $path): array {
    if (!file_exists($path)) {
        return news_store_empty();
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Archivio illeggibile: $path");
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
        throw new RuntimeException("Archivio corrotto o non decodificabile: $path (" . json_last_error_msg() . ')');
    }
    $data['_meta'] ??= ['last_run' => null, 'sources' => []];
    $data['_meta']['sources'] ??= [];
    return $data;
}

/**
 * Unisce le voci di una fonte a quelle già presenti. Non cancella mai: una fonte
 * che torna vuota o non risponde lascia intatto quanto già mostrato in pagina.
 */
function news_store_merge(array $store, string $sourceId, array $items, string $nowIso): array {
    $byId = [];
    foreach ($store['items'] as $existing) {
        $byId[$existing['id']] = $existing;
    }
    foreach ($items as $item) {
        if (isset($byId[$item['id']])) {
            // Si aggiornano titolo ed estratto (possono essere corretti a monte),
            // ma non la data: la prima rilevata è quella di pubblicazione.
            $byId[$item['id']]['title'] = $item['title'];
            $byId[$item['id']]['summary'] = $item['summary'];
            continue;
        }
        $byId[$item['id']] = $item;
    }
    $store['items'] = array_values($byId);
    $store['_meta']['sources'][$sourceId] = [
        'last_ok' => $nowIso,
        'last_error' => null,
        'consecutive_failures' => 0,
    ];
    return $store;
}

function news_store_mark_failure(array $store, string $sourceId, string $error, string $nowIso): array {
    $prev = $store['_meta']['sources'][$sourceId] ?? [
        'last_ok' => null, 'last_error' => null, 'consecutive_failures' => 0,
    ];
    $store['_meta']['sources'][$sourceId] = [
        'last_ok' => $prev['last_ok'],
        'last_error' => $error,
        'consecutive_failures' => ((int) $prev['consecutive_failures']) + 1,
    ];
    return $store;
}

function news_source_is_stale(array $store, string $sourceId, string $nowIso, int $hours = 48): bool {
    $lastOk = $store['_meta']['sources'][$sourceId]['last_ok'] ?? null;
    if ($lastOk === null) {
        return true;
    }
    return (strtotime($nowIso) - strtotime($lastOk)) > $hours * 3600;
}

/**
 * Ordina per data discendente e applica i due tetti: 90 giorni e 120 voci,
 * complessivi e non per fonte.
 */
function news_store_prune(array $store, string $nowIso, int $maxItems = 120, int $maxDays = 90): array {
    $cutoff = strtotime($nowIso) - $maxDays * 86400;
    $items = array_values(array_filter(
        $store['items'],
        static fn(array $i): bool => strtotime($i['date']) >= $cutoff
    ));
    usort($items, static fn(array $a, array $b): int => strtotime($b['date']) <=> strtotime($a['date']));
    $store['items'] = array_slice($items, 0, $maxItems);
    return $store;
}

function news_store_save(string $path, array $store): void {
    $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('json_encode fallito: ' . json_last_error_msg());
    }
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException("Scrittura fallita: $path");
    }
}
