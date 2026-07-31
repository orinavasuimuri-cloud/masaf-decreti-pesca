<?php
declare(strict_types=1);

function bandi_store_empty(): array {
    return [
        '_meta' => [
            'last_run' => null,
            'fonti' => [],
            'copertura' => ['attesi_api' => 0, 'raccolti' => 0, 'per_regione' => [], 'dichiarati_per_regione' => []],
        ],
        'items' => [],
    ];
}

/**
 * File assente e file corrotto sono fatti diversi. Il primo è lo stato
 * iniziale legittimo (prima installazione, prima esecuzione): si restituisce
 * l'archivio vuoto. Il secondo è un guasto - JSON troncato da una scrittura
 * parziale, permessi, disco pieno - e va segnalato con un'eccezione: se qui
 * degradasse in silenzio a "vuoto", il run successivo del fetcher farebbe
 * merge sul nulla e il salvataggio finale riscriverebbe l'archivio perdendo
 * per sempre tutte le voci già raccolte.
 */
function bandi_store_load(string $path): array {
    if (!file_exists($path)) {
        return bandi_store_empty();
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Archivio illeggibile: $path");
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
        throw new RuntimeException("Archivio corrotto o non decodificabile: $path (" . json_last_error_msg() . ')');
    }
    $vuoto = bandi_store_empty();
    $data['_meta'] ??= $vuoto['_meta'];
    $data['_meta']['fonti'] ??= [];
    $data['_meta']['copertura'] ??= $vuoto['_meta']['copertura'];
    return $data;
}

/** '', null, false, [] contano come "vuoto" ai fini della protezione in merge. */
function bandi_valore_vuoto(mixed $v): bool {
    return $v === '' || $v === null || $v === false || $v === [];
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
    $campi = ['titolo', 'scopo', 'priorita', 'codice_intervento', 'scadenza',
              'terminato_in_fonte', 'nota', 'url_ufficiale', 'regioni', 'dettagli_mancanti'];
    foreach ($voci as $voce) {
        if (isset($byId[$voce['id']])) {
            $vecchia = $byId[$voce['id']];
            foreach ($campi as $campo) {
                $nuovo = $voce[$campo];
                // Il titolo non è mai letteralmente vuoto: quando lo scopo manca,
                // bandi_parse_archivio() lo fa ricadere sulla priorità (etichetta
                // generica, non una stringa vuota). Ai fini della protezione va
                // trattato come degradato comunque, altrimenti "vuoto" non lo
                // intercetterebbe mai e l'etichetta generica sovrascriverebbe un
                // titolo buono.
                $nuovoDegradato = $campo === 'titolo'
                    ? ($voce['scopo'] === '' && $nuovo === $voce['priorita'])
                    : bandi_valore_vuoto($nuovo);
                // Si aggiorna ciò che la fonte può correggere in corsa - proroghe,
                // titoli, link al decreto - ma non quando la voce in arrivo segnala
                // dettagli_mancanti e il nuovo valore è degradato mentre quello già
                // in archivio è buono: altrimenti un cambio di markup (frequente,
                // è un tema Divi) azzererebbe scopo e codice_intervento e farebbe
                // ricadere il titolo sull'etichetta generica su ogni voce, comprese
                // quelle chiuse da anni.
                if (($voce['dettagli_mancanti'] ?? false) && $nuovoDegradato
                    && !bandi_valore_vuoto($vecchia[$campo])) {
                    continue;
                }
                $byId[$voce['id']][$campo] = $nuovo;
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
 *
 * "raccolti" conta solo le voci di origine aggregatore, non
 * count($store['items']): l'archivio include anche le segnalazioni dai feed
 * istituzionali, che l'API non censisce e che si accumulano senza mai essere
 * potate. Confrontarle con $attesiApi (che conta solo l'aggregatore) le
 * farebbe apparire sempre "sufficienti" anche quando la raccolta vera perde
 * bandi, perché l'archivio cresce comunque in modo monotono.
 *
 * $dichiaratiPerRegione porta i conteggi per categoria dell'API (stessa fonte
 * di $perRegione ma dal censimento, non dalla raccolta): la riconciliazione
 * per singola regione, oltre a quella sul totale, sta nel chiamante.
 */
function bandi_store_set_copertura(
    array $store,
    int $attesiApi,
    array $perRegione,
    array $dichiaratiPerRegione = []
): array {
    $raccoltiAggregatore = count(array_filter(
        $store['items'],
        static fn(array $v): bool => ($v['origine'] ?? null) === 'aggregatore'
    ));
    $store['_meta']['copertura'] = [
        'attesi_api' => $attesiApi,
        'raccolti' => $raccoltiAggregatore,
        'per_regione' => $perRegione,
        'dichiarati_per_regione' => $dichiaratiPerRegione,
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

/**
 * Scrittura atomica: si scrive su un file temporaneo e solo se è completa e
 * verificata si sostituisce l'originale con rename(). file_put_contents() su
 * scrittura parziale (disco pieno, interruzione) restituisce il numero di
 * byte scritti, non false: un controllo "=== false" da solo non la
 * intercetta e lascerebbe un JSON troncato al posto dell'archivio buono.
 * rename() sullo stesso filesystem è atomico anche su NTFS, quindi un lettore
 * concorrente vede sempre o il file vecchio intero o quello nuovo intero, mai
 * uno stato intermedio.
 */
function bandi_store_save(string $path, array $store): void {
    $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('json_encode fallito: ' . json_last_error_msg());
    }
    $tmp = $path . '.tmp';
    $scritti = file_put_contents($tmp, $json);
    if ($scritti === false || $scritti !== strlen($json)) {
        @unlink($tmp);
        throw new RuntimeException("Scrittura incompleta: $path");
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("Sostituzione del file fallita: $path");
    }
}
