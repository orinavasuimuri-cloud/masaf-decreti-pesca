<?php
declare(strict_types=1);

/**
 * Archivio delle voci lette dalla Gazzetta Ufficiale.
 *
 * Indicizzato per codice dell'atto, non per posizione: le voci vanno ritrovate
 * per aggiornarle senza duplicarle, e il codice e' l'unico identificatore
 * stabile che la fonte assegna.
 *
 * Non e' data/known.json: quel file e' indicizzato per IDPagina MASAF ed e' di
 * proprieta' di scraper.php. Infilarci voci con un identificatore di forma
 * diversa romperebbe il contratto fra i due programmi.
 */

function gazzetta_store_empty(): array
{
    return ['_meta' => ['last_run' => null, 'serie' => []], 'items' => []];
}

/**
 * File assente e file corrotto sono fatti diversi: il primo e' lo stato
 * iniziale legittimo, il secondo un guasto. Se qui il corrotto degradasse a
 * "vuoto", il salvataggio finale riscriverebbe l'archivio perdendo tutto.
 */
function gazzetta_store_load(string $path): array
{
    if (!file_exists($path)) {
        return gazzetta_store_empty();
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Archivio illeggibile: $path");
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
        throw new RuntimeException("Archivio corrotto o non decodificabile: $path (" . json_last_error_msg() . ')');
    }
    // `??=` da solo non basta: interviene su quello che manca o e' null, non su
    // quello che c'e' ma ha la forma sbagliata. Un _meta che fosse una stringa
    // passerebbe di qui intatto per poi far morire il merge con un errore
    // oscuro, lontano dalla causa. Anche questo e' un archivio corrotto, e va
    // detto qui, dove si sa qual e' il file.
    $meta = $data['_meta'] ?? ['last_run' => null, 'serie' => []];
    if (!is_array($meta)) {
        throw new RuntimeException("Archivio corrotto, _meta non e' un oggetto: $path");
    }
    $meta['serie'] ??= [];
    if (!is_array($meta['serie'])) {
        throw new RuntimeException("Archivio corrotto, _meta.serie non e' un oggetto: $path");
    }
    $meta['last_run'] ??= null;
    $data['_meta'] = $meta;
    return $data;
}

/** Scrittura atomica, come lib/bandi_store.php: una scrittura parziale non deve sostituire l'archivio buono. */
function gazzetta_store_save(string $path, array $store): void
{
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

/**
 * Unisce le voci di una serie a quelle gia' presenti.
 *
 * Lo 'status' non si aggiorna mai da fonte: e' l'unica informazione che la GU
 * non possiede, la decide il curatore. Sovrascriverlo rimetterebbe "da
 * rivedere" su ogni voce gia' valutata, a ogni esecuzione.
 */
function gazzetta_store_merge(array $store, string $serieId, array $voci, int $numero, string $data, string $nowIso): array
{
    foreach ($voci as $voce) {
        $id = (string) $voce['id'];
        if (isset($store['items'][$id])) {
            $voce['status']     = $store['items'][$id]['status'];
            $voce['first_seen'] = $store['items'][$id]['first_seen'];
        }
        $store['items'][$id] = $voce;
    }
    $precedente = $store['_meta']['serie'][$serieId] ?? [];
    $store['_meta']['serie'][$serieId] = [
        'ultimo_numero' => $numero,
        'ultima_data'   => $data,
        'last_ok'       => $nowIso,
        'errore'        => null,
        'saltati'       => $precedente['saltati'] ?? [],
    ];
    return $store;
}

/** Registra il guasto senza perdere l'ultimo numero visto: al prossimo giro serve per contare i salti. */
function gazzetta_store_mark_failure(array $store, string $serieId, string $errore, string $nowIso): array
{
    $precedente = $store['_meta']['serie'][$serieId] ?? [
        'ultimo_numero' => null, 'ultima_data' => null, 'last_ok' => null, 'saltati' => [],
    ];
    $store['_meta']['serie'][$serieId] = [
        'ultimo_numero' => $precedente['ultimo_numero'] ?? null,
        'ultima_data'   => $precedente['ultima_data'] ?? null,
        'last_ok'       => $precedente['last_ok'] ?? null,
        'errore'        => $errore,
        'saltati'       => $precedente['saltati'] ?? [],
    ];
    return $store;
}

/**
 * I numeri di fascicolo non visti fra l'ultima esecuzione e questa.
 *
 * Il feed e' il sommario di un solo numero: se il job salta due giorni, quei
 * fascicoli sono persi e non c'e' modo di derivarne le date dal feed. Si
 * segnalano invece di ricostruirli: un recupero che funziona a volte e' peggio
 * di un avviso che si legge.
 *
 * Il numero riparte da 1 a ogni anno solare, quindi un salto all'indietro non
 * e' un buco ma un capodanno.
 *
 * @return list<int>
 */
function gazzetta_numeri_saltati(?int $ultimo, int $corrente): array
{
    // La soglia e' 2, non 0: con numeri consecutivi (177 -> 178) l'intervallo
    // dei mancanti sarebbe range(178, 177), che in PHP non e' vuoto ma
    // discendente, e segnalerebbe come saltati due fascicoli entrambi visti.
    if ($ultimo === null || $corrente - $ultimo < 2) {
        return [];
    }
    return range($ultimo + 1, $corrente - 1);
}
