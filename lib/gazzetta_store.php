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
/**
 * Mette le voci in archivio conservando quello che sappiamo noi e la fonte no.
 *
 * Estratta da gazzetta_store_merge() perche' la usa anche il recupero di un
 * fascicolo arretrato, che deve inserire le voci nello stesso modo ma non puo'
 * dichiarare il proprio numero come ultimo visto.
 *
 * @param array<string, array> $items
 * @param list<array> $voci
 * @return array<string, array>
 */
function gazzetta_items_merge(array $items, array $voci): array
{
    foreach ($voci as $voce) {
        $id = (string) $voce['id'];
        if (isset($items[$id])) {
            $voce['status']     = $items[$id]['status'];
            $voce['first_seen'] = $items[$id]['first_seen'];
            // Come lo status: e' un fatto sul nostro conto, non sull'atto. La
            // fonte non sa se l'abbiamo gia' messo nella pagina dei bandi, e
            // riportarlo a false a ogni riletura dello stesso fascicolo
            // farebbe ritravasare all'infinito voci gia' pubblicate.
            $voce['travasato'] = $items[$id]['travasato'] ?? false;
        }
        $items[$id] = $voce;
    }
    return $items;
}

function gazzetta_store_merge(array $store, string $serieId, array $voci, int $numero, string $data, string $nowIso): array
{
    $store['items'] = gazzetta_items_merge($store['items'], $voci);
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

/**
 * Come un fascicolo saltato viene annotato: "anno/numero", per esempio
 * "2026/181".
 *
 * Il numero da solo non basta, e non e' un dettaglio formale. La numerazione
 * riparte da 1 ogni gennaio, quindi un 305 annotato a dicembre 2026 il dicembre
 * dopo esiste di nuovo: il recupero lo cercherebbe nell'archivio dell'anno
 * corrente, lo troverebbe, e scaricherebbe il fascicolo sbagliato. Nemmeno il
 * controllo sul numero restituito se ne accorgerebbe, perche' il numero e'
 * proprio quello chiesto - a differire e' l'anno.
 */
function gazzetta_saltato_chiave(int $numero, string $anno): string
{
    return $anno . '/' . $numero;
}

/**
 * Rilegge un elemento dell'elenco dei saltati.
 *
 * Accetta anche il vecchio formato, il numero nudo, per gli archivi scritti
 * prima che l'anno venisse annotato: in quel caso vale $annoRipiego, che chi
 * chiama ricava dall'ultimo fascicolo visto. E' la stessa assunzione che si
 * faceva allora, resa esplicita invece che implicita.
 *
 * @param int|string $voce
 * @return array{numero:int, anno:string}
 */
function gazzetta_saltato_scomponi($voce, string $annoRipiego): array
{
    if (is_string($voce) && str_contains($voce, '/')) {
        [$anno, $numero] = explode('/', $voce, 2);
        return ['numero' => (int) $numero, 'anno' => $anno];
    }
    return ['numero' => (int) $voce, 'anno' => $annoRipiego];
}

/**
 * Archivia un fascicolo arretrato e lo toglie dall'elenco dei saltati.
 *
 * Non passa da gazzetta_store_merge() per una ragione sola ma decisiva: quello
 * dichiara il fascicolo appena letto come ultimo visto della serie, e qui il
 * fascicolo e' vecchio. Riportare 'ultimo_numero' indietro al 181 dopo aver
 * visto il 183 farebbe ricomparire 182 e 183 fra i saltati al giro successivo,
 * cioe' il recupero si inventerebbe da solo i buchi da recuperare.
 *
 * Nemmeno 'last_ok' viene toccato: quello dice quando la serie e' stata letta
 * dalla sua sorgente corrente, ed e' li' che si guarda per sapere se il feed
 * risponde ancora.
 *
 * @param list<array> $voci
 * @param int|string $saltato voce dell'elenco dei saltati, nel formato di gazzetta_saltato_chiave()
 */
function gazzetta_store_recupera(array $store, string $serieId, array $voci, $saltato, string $annoRipiego = ''): array
{
    $store['items'] = gazzetta_items_merge($store['items'], $voci);

    $cercato = gazzetta_saltato_scomponi($saltato, $annoRipiego);
    $saltati = $store['_meta']['serie'][$serieId]['saltati'] ?? [];
    $store['_meta']['serie'][$serieId]['saltati'] = array_values(array_filter(
        $saltati,
        static function ($voce) use ($cercato, $annoRipiego): bool {
            $v = gazzetta_saltato_scomponi($voce, $annoRipiego);
            return $v !== $cercato;
        }
    ));
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
 * Le voci dirette ai bandi che non sono ancora finite in data/bandi.json.
 *
 * Il travaso puo' fallire per conto suo (archivio dei bandi non scrivibile,
 * disco pieno) mentre la raccolta e' andata bene. Prima si ripartiva dalle
 * voci lette nel giro corrente: al giro dopo il feed era gia' passato al
 * fascicolo successivo, quelle voci non erano piu' fra le "appena lette" e
 * nessuno le riprovava mai piu'. Restavano in archivio, invisibili nella
 * pagina dei bandi, e il log del fallimento era di giorni prima.
 *
 * Ripartendo dall'archivio invece che dal giro, il ritentativo e' automatico e
 * non ha bisogno di ricordare nulla fra un'esecuzione e l'altra.
 *
 * @return list<array>
 */
function gazzetta_da_travasare(array $items): array
{
    $out = [];
    foreach ($items as $voce) {
        if (($voce['destinazione'] ?? '') === 'bandi' && ($voce['travasato'] ?? false) !== true) {
            $out[] = $voce;
        }
    }
    return $out;
}

/**
 * Segna come travasate le voci indicate, dopo che l'archivio dei bandi e'
 * stato salvato davvero.
 *
 * L'ordine conta: prima si salva data/bandi.json, poi si marca qui, poi si
 * risalva l'archivio della Gazzetta. Se l'ultimo salvataggio non riesce, le
 * voci risultano ancora da travasare e il giro dopo ci riprova - il travaso e'
 * idempotente, perche' bandi_store_merge() aggiorna per id invece di
 * accodare. Marcare prima del salvataggio darebbe l'errore opposto, molto
 * peggiore: voci date per pubblicate che non lo sono, e nessuno ci torna piu'.
 *
 * @param list<string> $ids
 */
function gazzetta_marca_travasate(array $store, array $ids): array
{
    foreach ($ids as $id) {
        if (isset($store['items'][(string) $id])) {
            $store['items'][(string) $id]['travasato'] = true;
        }
    }
    return $store;
}

/**
 * Il feed e' fermo: continua a rispondere, ma sull'ultimo fascicolo da giorni.
 *
 * gazzetta_numeri_saltati() vede i buchi fra due numeri, non la stagnazione:
 * se la fonte smette di aggiornarsi (manutenzione, cambio di formato, un
 * indirizzo che risponde con l'ultimo sommario in cache) il fetcher continua a
 * rileggere lo stesso numero, non salta nulla, e i log restano tutti verdi
 * mentre non arriva piu' niente. E' il guasto che passa inosservato piu' a
 * lungo, perche' non somiglia a un guasto.
 *
 * La soglia si misura sulla data del fascicolo, non su quella dell'ultima
 * esecuzione riuscita: e' la fonte a doversi muovere, non noi. Quattro giorni
 * perche' la Gazzetta non esce nei festivi e un ponte lungo non e' un guasto.
 */
function gazzetta_feed_fermo(?string $ultimaData, string $oggi, int $giorni = 4): bool
{
    if ($ultimaData === null || $ultimaData === '') {
        return false;
    }
    $data = strtotime($ultimaData);
    $ora  = strtotime($oggi);
    if ($data === false || $ora === false) {
        return false;
    }
    return ($ora - $data) > $giorni * 86400;
}

/**
 * I numeri di fascicolo non visti fra l'ultima esecuzione e questa.
 *
 * Il feed e' il sommario di un solo numero: se il job salta due giorni, quei
 * fascicoli non passano piu' di li'. I numeri restano annotati in
 * _meta.serie[id].saltati, dove il fetcher li ripesca per andarseli a leggere
 * uno per uno dalle rispettive pagine.
 *
 * Il recupero e' arrivato dopo, e per un motivo preciso: prima la data di un
 * fascicolo passato si sarebbe dovuta indovinare contando i giorni feriali
 * all'indietro, e un recupero che sbaglia sulle feste e' peggio di un avviso
 * che si legge. Con l'archivio annuale della GU la corrispondenza fra numero e
 * data la dichiara la fonte, e indovinare non serve piu'.
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
