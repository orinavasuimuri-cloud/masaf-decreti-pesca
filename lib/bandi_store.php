<?php
declare(strict_types=1);

function bandi_store_empty(): array {
    return [
        '_meta' => [
            'last_run' => null,
            'fonti' => [],
            'copertura' => ['attesi_api' => 0, 'raccolti' => 0, 'per_regione' => []],
        ],
        'items' => [],
    ];
}

function bandi_store_load(string $path): array {
    if (!file_exists($path)) {
        return bandi_store_empty();
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
        return bandi_store_empty();
    }
    $vuoto = bandi_store_empty();
    $data['_meta'] ??= $vuoto['_meta'];
    $data['_meta']['fonti'] ??= [];
    $data['_meta']['copertura'] ??= $vuoto['_meta']['copertura'];
    return $data;
}

/**
 * Unisce le voci di una fonte a quelle già presenti. Non cancella mai: una fonte
 * che torna vuota o non risponde lascia intatto l'archivio. Non c'è potatura,
 * per scelta: i bandi chiusi sono il valore della pagina.
 */
function bandi_store_merge(array $store, string $fonteId, array $voci, string $nowIso): array {
    $byId = [];
    foreach ($store['items'] as $existing) {
        $byId[$existing['id']] = $existing;
    }
    foreach ($voci as $voce) {
        if (isset($byId[$voce['id']])) {
            // Si aggiorna ciò che la fonte può correggere in corsa - proroghe,
            // titoli, link al decreto - ma non la data di pubblicazione, che è
            // quella della prima rilevazione.
            foreach (['titolo', 'scopo', 'priorita', 'codice_intervento', 'scadenza',
                      'terminato_in_fonte', 'nota', 'url_ufficiale', 'regioni',
                      'dettagli_mancanti'] as $campo) {
                $byId[$voce['id']][$campo] = $voce[$campo];
            }
            continue;
        }
        $byId[$voce['id']] = $voce;
    }
    $store['items'] = array_values($byId);
    $store['_meta']['fonti'][$fonteId] = [
        'last_ok' => $nowIso,
        'last_error' => null,
        'consecutive_failures' => 0,
    ];
    return $store;
}

function bandi_store_mark_failure(array $store, string $fonteId, string $errore, string $nowIso): array {
    $prev = $store['_meta']['fonti'][$fonteId] ?? [
        'last_ok' => null, 'last_error' => null, 'consecutive_failures' => 0,
    ];
    $store['_meta']['fonti'][$fonteId] = [
        'last_ok' => $prev['last_ok'],
        'last_error' => $errore,
        'consecutive_failures' => ((int) $prev['consecutive_failures']) + 1,
    ];
    return $store;
}

/**
 * Registra lo scarto fra quanto l'API dichiara e quanto è stato davvero
 * raccolto. Senza questo confronto un cambio di paginazione farebbe perdere
 * bandi in silenzio.
 */
function bandi_store_set_copertura(array $store, int $attesiApi, array $perRegione): array {
    $store['_meta']['copertura'] = [
        'attesi_api' => $attesiApi,
        'raccolti' => count($store['items']),
        'per_regione' => $perRegione,
    ];
    return $store;
}

/** Soglia più larga di quella delle news: i bandi cambiano in settimane. */
function bandi_fonte_is_stale(array $store, string $fonteId, string $nowIso, int $giorni = 7): bool {
    $lastOk = $store['_meta']['fonti'][$fonteId]['last_ok'] ?? null;
    if ($lastOk === null) {
        return true;
    }
    return (strtotime($nowIso) - strtotime($lastOk)) > $giorni * 86400;
}

function bandi_store_save(string $path, array $store): void {
    $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('json_encode fallito: ' . json_last_error_msg());
    }
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException("Scrittura fallita: $path");
    }
}
