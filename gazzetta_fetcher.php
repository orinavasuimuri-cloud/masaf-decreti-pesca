<?php
declare(strict_types=1);

/**
 * Scarica i sommari delle serie della Gazzetta Ufficiale, tiene le voci in tema
 * e le smista fra la coda di revisione del registro e la pagina dei bandi.
 *
 * Ogni serie e' isolata: una che fallisce non impedisce alle altre di
 * aggiornarsi. L'archivio non viene mai potato.
 *
 * Uso: php gazzetta_fetcher.php
 */

require_once __DIR__ . '/lib/gazzetta_parser.php';
require_once __DIR__ . '/lib/gazzetta_store.php';
require_once __DIR__ . '/lib/news_normalize.php';
require_once __DIR__ . '/lib/bandi_parser.php';
require_once __DIR__ . '/lib/bandi_store.php';
require_once __DIR__ . '/lib/lock.php';

// date.timezone e' UTC sul server: senza questo ogni timestamp sarebbe sfasato
// di due ore rispetto all'ora italiana.
date_default_timezone_set('Europe/Rome');

$dataDir    = __DIR__ . '/data';
$storeFile  = $dataDir . '/gazzetta.json';
$bandiFile  = $dataDir . '/bandi.json';
$fontiFile  = $dataDir . '/gazzetta_fonti.json';
$logFile    = $dataDir . '/gazzetta.log';

function gz_log(string $msg, string $logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

// Questo fetcher e' l'unico che scrive in due archivi, data/gazzetta.json e
// data/bandi.json: il secondo appartiene a bandi_fetcher.php, ed e' proprio la
// sovrapposizione fra i due che il lock deve impedire.
lock_o_esci($dataDir . '/.fetch.lock', static function (string $m) use ($logFile): void {
    gz_log($m, $logFile);
});

/** Come negli altri fetcher: senza openssl i wrapper https:// non esistono. */
function gz_fetch(string $url): string {
    $cmd = sprintf(
        'curl -s -L -A %s --max-time 25 %s',
        escapeshellarg('Mozilla/5.0 (Windows NT 10.0; Win64; x64) masaf-decreti-pesca-gazzetta/1.0'),
        escapeshellarg($url)
    );
    $body = shell_exec($cmd);
    if ($body === null || trim($body) === '') {
        throw new RuntimeException("download fallito (curl): $url");
    }
    return $body;
}

$fonti = json_decode((string) @file_get_contents($fontiFile), true);
if (!is_array($fonti) || !isset($fonti['serie']) || !is_array($fonti['serie'])) {
    gz_log('ERRORE: data/gazzetta_fonti.json mancante o non valido', $logFile);
    exit(1);
}

$now  = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('c');
$oggi = date('Y-m-d');

try {
    $store = gazzetta_store_load($storeFile);
} catch (Throwable $e) {
    // Archivio presente ma illeggibile: e' un guasto, non un archivio vuoto.
    // Si esce prima di toccarlo, cosi' un run successivo con il file riparato
    // non lo trova gia' svuotato.
    gz_log('ERRORE caricamento archivio: ' . $e->getMessage(), $logFile);
    exit(1);
}

$ok = 0;
$ko = 0;

foreach ($fonti['serie'] as $serie) {
    $id = (string) $serie['id'];
    try {
        $sommario = gazzetta_parse_sommario(gz_fetch((string) $serie['url']));

        $ultimo = $store['_meta']['serie'][$id]['ultimo_numero'] ?? null;
        $saltati = gazzetta_numeri_saltati($ultimo, $sommario['numero']);
        if ($saltati !== []) {
            gz_log(
                "$id: ATTENZIONE, non visti i fascicoli " . implode(', ', $saltati)
                . ' (dal ' . $ultimo . ' al ' . $sommario['numero'] . ')',
                $logFile
            );
        }

        // Una numerazione che riparte da capo e' un capodanno, non un buco, e
        // gazzetta_numeri_saltati() infatti non segnala niente. Resta pero' che
        // i fascicoli di fine dicembre non ancora visti non li vedra' piu'
        // nessuno: non entrano fra i saltati e nessuno andra' a cercarli. Se ne
        // scrive qui, perche' un limite noto detto dal log si affronta, uno
        // taciuto nel codice no.
        if ($ultimo !== null && $sommario['numero'] < $ultimo) {
            gz_log(
                "$id: la numerazione riparte ($ultimo -> {$sommario['numero']}): se fra i due"
                . ' e\' rimasto qualche fascicolo dell\'anno prima, va recuperato a mano',
                $logFile
            );
        }

        // Un buco fra due numeri lo vede gazzetta_numeri_saltati(); una fonte
        // che risponde sempre con lo stesso fascicolo non salta nulla e non
        // verrebbe segnalata da niente. Si guarda la data del fascicolo, che e'
        // quella che deve avanzare.
        if (gazzetta_feed_fermo($sommario['data'], $oggi)) {
            gz_log(
                "$id: ATTENZIONE, il sommario piu' recente e' ancora quello del {$sommario['data']}"
                . " (fascicolo {$sommario['numero']}): la fonte sembra ferma",
                $logFile
            );
        }

        $voci = gazzetta_voci($sommario, $id, $serie['keywords'] ?? [], $oggi);
        $store = gazzetta_store_merge($store, $id, $voci, $sommario['numero'], $sommario['data'], $now);
        if ($saltati !== []) {
            // Si annota l'anno del fascicolo appena letto: i numeri saltati
            // stanno per costruzione fra l'ultimo visto e questo, quindi sono
            // suoi. Senza l'anno, un numero rimasto in elenco a cavallo di
            // capodanno tornerebbe buono per il fascicolo omonimo dell'anno dopo.
            $annoCorrente = substr($sommario['data'], 0, 4);
            $chiavi = array_map(
                static fn(int $n): string => gazzetta_saltato_chiave($n, $annoCorrente),
                $saltati
            );
            $store['_meta']['serie'][$id]['saltati'] = array_values(array_unique(array_merge(
                $store['_meta']['serie'][$id]['saltati'] ?? [],
                $chiavi
            )));
        }

        gz_log(
            "$id: fascicolo {$sommario['numero']} del {$sommario['data']}, "
            . count($sommario['items']) . ' atti letti, ' . count($voci) . ' in tema',
            $logFile
        );
        $ok++;
    } catch (Throwable $e) {
        $store = gazzetta_store_mark_failure($store, $id, $e->getMessage(), $now);
        gz_log("$id: ERRORE " . $e->getMessage(), $logFile);
        $ko++;
    }
}

// --- recupero dei fascicoli saltati ---
// I numeri annotati da gazzetta_numeri_saltati() non tornano piu' nel feed, che
// pubblica solo l'ultimo uscito: vanno letti dalla pagina del singolo fascicolo,
// il cui indirizzo si costruisce con la data, che a sua volta si legge
// dall'archivio annuale. Si fa qui, prima del salvataggio e del travaso, cosi'
// le voci recuperate seguono la stessa strada di quelle appena lette.
//
// Il tetto per esecuzione esiste perche' un archivio rimasto indietro di mesi
// farebbe partire centinaia di richieste tutte insieme, e la GU e' una fonte
// pubblica: i fascicoli avanzati restano in elenco e toccano al giro dopo.
// Il tetto e' doppio, di conteggio e di tempo, e il secondo serve piu' del
// primo: il recupero gira tenendo il lock condiviso con gli altri fetcher, e
// dieci richieste su una Gazzetta lenta lo terrebbero occupato per minuti. Chi
// aspetta ha cinque minuti di pazienza (LOCK_ATTESA_FETCHER): sforarli
// vorrebbe dire far saltare la raccolta a bandi e notizie, che con la Gazzetta
// non c'entrano niente. Quello che avanza tocca al giro dopo.
const GZ_RECUPERI_PER_GIRO = 10;
const GZ_SECONDI_PER_RECUPERI = 120;

$scadenzaRecuperi = microtime(true) + GZ_SECONDI_PER_RECUPERI;
$archiviAnno = [];
$recuperati = 0;

foreach ($fonti['serie'] as $serie) {
    $id = (string) $serie['id'];
    $saltati = $store['_meta']['serie'][$id]['saltati'] ?? [];
    if ($saltati === []) {
        continue;
    }

    if (!isset($serie['archivio_anno'], $serie['sommario'])) {
        gz_log("$id: " . count($saltati) . ' fascicoli saltati, ma la serie non dice dove rileggerli'
            . " (mancano 'archivio_anno' e 'sommario' in gazzetta_fonti.json)", $logFile);
        continue;
    }

    // L'anno di ripiego serve solo agli elenchi scritti prima che l'anno
    // venisse annotato accanto al numero: da allora ogni voce se lo porta.
    $annoRipiego = substr((string) ($store['_meta']['serie'][$id]['ultima_data'] ?? ''), 0, 4);
    if ($annoRipiego === '') {
        gz_log("$id: fascicoli saltati ma nessuna data nota per la serie, recupero rimandato", $logFile);
        continue;
    }

    // Dal piu' recente: sono quelli che interessano davvero, e se in coda
    // restasse un fascicolo irrecuperabile - un numero che la Gazzetta non ha
    // mai pubblicato - partendo dal basso si mangerebbe ogni giro il posto di
    // quelli buoni, e il recupero si fermerebbe senza che nulla lo dica.
    //
    // L'ordinamento e' sui due campi separati, non sulla stringa intera:
    // rsort() metterebbe "2026/99" davanti a "2026/178", perche' confronta
    // carattere per carattere e '9' viene dopo '1'.
    usort($saltati, static function ($a, $b) use ($annoRipiego): int {
        $x = gazzetta_saltato_scomponi($a, $annoRipiego);
        $y = gazzetta_saltato_scomponi($b, $annoRipiego);
        return [$y['anno'], $y['numero']] <=> [$x['anno'], $x['numero']];
    });

    // Un archivio annuale che non risponde vale per tutte le voci di quell'anno:
    // si annota il fallimento e si passa alle altre, invece di fermare tutto.
    // Fermarsi vorrebbe dire che una sola voce di un anno irraggiungibile
    // impedisce per sempre il recupero di quelle recuperabili.
    $anniFalliti = [];

    foreach (array_slice($saltati, 0, GZ_RECUPERI_PER_GIRO) as $voce) {
        if (microtime(true) > $scadenzaRecuperi) {
            gz_log("$id: tempo per i recuperi esaurito, il resto al prossimo giro", $logFile);
            break;
        }

        ['numero' => $numero, 'anno' => $anno] = gazzetta_saltato_scomponi($voce, $annoRipiego);
        if (isset($anniFalliti[$anno])) {
            continue;
        }

        try {
            // La cache e' per serie e anno insieme: l'indirizzo dell'archivio
            // arriva da $serie, quindi due serie diverse nello stesso anno hanno
            // calendari diversi e confonderli darebbe date sbagliate.
            $chiaveArchivio = $id . '|' . $anno;
            if (!isset($archiviAnno[$chiaveArchivio])) {
                $archiviAnno[$chiaveArchivio] = gazzetta_parse_archivio_anno(
                    gz_fetch(str_replace('{anno}', $anno, (string) $serie['archivio_anno']))
                );
            }
            $calendario = $archiviAnno[$chiaveArchivio];
        } catch (Throwable $e) {
            $anniFalliti[$anno] = true;
            gz_log("$id: archivio $anno non leggibile, i suoi fascicoli restano fra i saltati: "
                . $e->getMessage(), $logFile);
            continue;
        }

        $dataFascicolo = $calendario[$numero] ?? null;
        if ($dataFascicolo === null) {
            gz_log("$id: fascicolo $numero non risulta nell'archivio $anno, resta fra i saltati", $logFile);
            continue;
        }

        [$aa, $mm, $gg] = explode('-', $dataFascicolo);
        $urlFascicolo = strtr((string) $serie['sommario'], [
            '{anno}' => $aa, '{mese}' => $mm, '{giorno}' => $gg, '{numero}' => (string) $numero,
        ]);

        try {
            $arretrato = gazzetta_parse_sommario_html(gz_fetch($urlFascicolo));

            // La pagina dichiara il proprio numero, e si controlla che sia
            // quello chiesto: un indirizzo sbagliato non da' errore, da' un
            // altro fascicolo, e gli atti finirebbero archiviati sotto una data
            // che non e' la loro.
            // Si controlla numero e data insieme. Il numero da solo non basta:
            // ogni anno ne esiste uno uguale, e un fascicolo chiesto per l'anno
            // sbagliato risponderebbe col numero giusto e il contenuto di un
            // altro anno. La data e' quella che l'archivio ha dichiarato.
            if ($arretrato['numero'] !== $numero || $arretrato['data'] !== $dataFascicolo) {
                gz_log("$id: chiesto il fascicolo $numero del $dataFascicolo, risponde il"
                    . " {$arretrato['numero']} del {$arretrato['data']}: si lascia stare", $logFile);
                continue;
            }

            $voci = gazzetta_voci($arretrato, $id, $serie['keywords'] ?? [], $oggi);
            $store = gazzetta_store_recupera($store, $id, $voci, $voce, $annoRipiego);
            $recuperati++;
            gz_log("$id: recuperato il fascicolo $numero del {$arretrato['data']}, "
                . count($arretrato['items']) . ' atti letti, ' . count($voci) . ' in tema', $logFile);
        } catch (Throwable $e) {
            // Un fascicolo che non si legge non ferma gli altri e non esce
            // dall'elenco: al giro dopo ci si riprova.
            gz_log("$id: fascicolo $numero non recuperato: " . $e->getMessage(), $logFile);
        }
    }

    $rimasti = $store['_meta']['serie'][$id]['saltati'] ?? [];
    if ($rimasti !== []) {
        gz_log("$id: resta" . (count($rimasti) === 1 ? ' 1 fascicolo' : 'no ' . count($rimasti) . ' fascicoli')
            . ' da recuperare: ' . implode(', ', $rimasti), $logFile);
    }
}

$store['_meta']['last_run'] = $now;

try {
    gazzetta_store_save($storeFile, $store);
} catch (Throwable $e) {
    gz_log('ERRORE salvataggio: ' . $e->getMessage(), $logFile);
    exit(1);
}

// --- travaso verso la pagina dei bandi ---
// Le voci classificate come bandi entrano nell'archivio dei bandi con la stessa
// forma delle segnalazioni dai feed: senza scadenza e marcate come incomplete,
// perche' la GU pubblica l'atto, non il termine di partecipazione.
//
// Si riparte dall'archivio e non dalle voci appena lette: cosi' un travaso
// fallito viene ritentato al giro dopo, quando il fascicolo di oggi non e' piu'
// quello corrente. Vedi gazzetta_da_travasare().
$perBandi = gazzetta_da_travasare($store['items']);
if ($perBandi !== []) {
    try {
        $bandi = bandi_store_load($bandiFile);
        $vociBandi = [];
        $idTravasati = [];
        foreach ($perBandi as $voce) {
            $idTravasati[] = (string) $voce['id'];
            $vociBandi[] = bandi_voce([
                'id'                => news_item_id($voce['url']),
                'origine'           => 'gazzetta',
                'regioni'           => [],
                'titolo'            => $voce['oggetto'],
                'scopo'             => $voce['oggetto'],
                'pubblicazione'     => $voce['data_gu'],
                'scadenza'          => null,
                'nota'              => 'Pubblicato in Gazzetta Ufficiale n. ' . $voce['numero_gu'] . ' del ' . $voce['data_gu'],
                'url_fonte'         => $voce['url'],
                'url_ufficiale'     => $voce['url'],
                'dettagli_mancanti' => true,
                'fonte_label'       => 'Gazzetta Ufficiale',
            ]);
        }
        $bandi = bandi_store_merge($bandi, 'gu-sg', $vociBandi, $now);
        bandi_store_save($bandiFile, $bandi);

        // Solo ora che data/bandi.json e' su disco le voci si possono dare per
        // pubblicate. Se questo secondo salvataggio fallisce restano da
        // travasare e il giro dopo ci riprova: bandi_store_merge() aggiorna
        // per id, quindi ripassarci non duplica nulla.
        $store = gazzetta_marca_travasate($store, $idTravasati);
        gazzetta_store_save($storeFile, $store);
        gz_log(count($vociBandi) . ' voci travasate nella pagina dei bandi', $logFile);
    } catch (Throwable $e) {
        // Il travaso fallito non annulla la raccolta: l'archivio GU e' gia'
        // salvato e le voci restano marcate da travasare, quindi il giro dopo
        // ci riprova davvero - prima questo commento prometteva un ritentativo
        // che non avveniva, perche' si ripartiva dalle sole voci del fascicolo
        // corrente.
        gz_log('ERRORE travaso bandi: ' . $e->getMessage(), $logFile);
    }
}

$daRivedere = count(array_filter(
    $store['items'],
    static fn(array $v): bool => $v['status'] === 'pending_review' && $v['destinazione'] === 'registro'
));
gz_log("Serie ok: $ok, fallite: $ko, voci in archivio: " . count($store['items']) . ", da rivedere: $daRivedere", $logFile);
exit($ok > 0 ? 0 : 1);
