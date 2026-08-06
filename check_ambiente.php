<?php
declare(strict_types=1);

/**
 * Verifica che questo server possa ospitare il progetto.
 *
 * Da eseguire per primo, prima di configurare qualunque altra cosa: dice in
 * chiaro cosa funzionera' e cosa no, invece di far scoprire i limiti
 * dell'ambiente a un fetcher che fallisce in silenzio nel cuore della notte.
 *
 * Si puo' lanciare da riga di comando (php check_ambiente.php) o aprendolo nel
 * browser. Vanno provati entrambi: sugli hosting condivisi la configurazione
 * di PHP per la CLI e quella per il web sono spesso diverse, ed e' la CLI a
 * contare per i programmi di raccolta.
 *
 * NON va lasciato raggiungibile dal web una volta finita l'installazione:
 * racconta com'e' fatto il server, che e' esattamente cio' che serve a chi
 * cerca un modo per entrarci.
 */

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
}

$esiti = [];

/** @param 'ok'|'avviso'|'guasto' $stato */
function esito(string $stato, string $titolo, string $dettaglio, string $conseguenza = ''): void
{
    global $esiti;
    $esiti[] = ['stato' => $stato, 'titolo' => $titolo, 'dettaglio' => $dettaglio, 'conseguenza' => $conseguenza];
}

// --- versione di PHP ---
// Il codice usa strict_types, match, arrow function, catch senza variabile e
// l'operatore nullsafe: sotto 8.0 non parte affatto.
if (PHP_VERSION_ID >= 80100) {
    esito('ok', 'Versione di PHP', PHP_VERSION . ' (' . PHP_SAPI . ')');
} elseif (PHP_VERSION_ID >= 80000) {
    esito('avviso', 'Versione di PHP', PHP_VERSION . ' (' . PHP_SAPI . ')',
        'Funziona, ma 8.0 non riceve piu\' aggiornamenti di sicurezza: meglio 8.1 o superiore.');
} else {
    esito('guasto', 'Versione di PHP', PHP_VERSION . ' (' . PHP_SAPI . ')',
        'Serve PHP 8.0 o superiore. Sotto questa versione il codice non si avvia.');
}

// --- estensioni ---
// dom e simplexml leggono i feed e le pagine delle fonti; json e' l'archivio;
// iconv normalizza le pagine in ISO-8859-1 (il MASAF le serve cosi').
//
// mbstring non c'e' di proposito e non va cercato: lib/news_normalize.php usa
// iconv apposta per non dipenderne. Chiederlo qui manderebbe il sistemista a
// installare qualcosa che non serve, o a dichiarare inadatto un server che va
// benissimo - e' successo alla prima stesura di questo file.
foreach ([
    'dom'       => 'lettura delle pagine HTML delle fonti',
    'simplexml' => 'lettura dei feed RSS',
    'json'      => 'archivi in data/',
    'iconv'     => 'conversione delle pagine non UTF-8',
] as $ext => $aCosaServe) {
    if (extension_loaded($ext)) {
        esito('ok', "Estensione $ext", "presente ($aCosaServe)");
    } else {
        esito('guasto', "Estensione $ext", "assente ($aCosaServe)",
            "Va abilitata: senza, la raccolta non funziona.");
    }
}

// --- come si raggiunge la rete ---
// I fetcher oggi scaricano con shell_exec('curl') perche' sulla macchina di
// sviluppo openssl non e' abilitato. Sul server puo' andare diversamente, e
// conviene saperlo prima: se c'e' openssl si puo' togliere la dipendenza da
// shell_exec, che molti hosting disattivano per sicurezza.
$shellExec = function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
$https = in_array('https', stream_get_wrappers(), true);
$curlExt = extension_loaded('curl');

$curlBin = $shellExec ? @shell_exec('curl --version 2>&1') : null;
$haCurlBin = is_string($curlBin) && str_contains(strtolower($curlBin), 'curl');

// Un verdetto solo, invece di tre voci da mettere insieme a mente: la domanda
// e' se la raccolta puo' uscire in rete, e con quanto lavoro.
$disponibili = array_filter([
    $haCurlBin ? "shell_exec + curl" : null,
    $https ? "openssl (https://)" : null,
    $curlExt ? "estensione curl di PHP" : null,
]);

if ($haCurlBin) {
    esito('ok', 'Uscita in rete', implode(', ', $disponibili),
        'I cinque programmi di raccolta funzionano cosi\' come sono, senza modifiche.');
} elseif ($https || $curlExt) {
    esito('avviso', 'Uscita in rete', implode(', ', $disponibili) . ' (ma shell_exec non e\' utilizzabile)',
        'Il sito funziona subito: le pagine leggono solo i file in data/. La raccolta va adattata a questa '
        . 'strada - e\' un intervento su una funzione per programma, gz_fetch() e le sue sorelle.');
} else {
    esito('guasto', 'Uscita in rete', 'nessuna strada disponibile',
        'Mancano insieme shell_exec+curl, openssl e l\'estensione curl: i programmi di raccolta non hanno '
        . 'modo di scaricare nulla. Il sito resta pubblicabile, ma i dati andrebbero aggiornati altrove '
        . 'e caricati a mano.');
}

// --- scrittura ---
// I fetcher riscrivono gli archivi in data/ e ci tengono i log e il lock.
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    esito('guasto', 'Cartella data/', 'non trovata', 'Il progetto non e\' stato copiato per intero.');
} elseif (!is_writable($dataDir)) {
    esito('guasto', 'Scrittura su data/', 'non consentita all\'utente ' . (function_exists('get_current_user') ? get_current_user() : '?'),
        'I fetcher non possono salvare. Vanno dati i permessi di scrittura a questa cartella.');
} else {
    // Non basta is_writable: su alcuni filesystem mente. Si prova davvero,
    // compreso il rename, che e' il meccanismo della scrittura atomica.
    $prova = $dataDir . '/.prova_scrittura';
    $scritto = @file_put_contents($prova, 'x') !== false;
    $rinominato = $scritto && @rename($prova, $prova . '2');
    @unlink($prova);
    @unlink($prova . '2');
    if ($rinominato) {
        esito('ok', 'Scrittura su data/', 'consentita, rename incluso (serve alla scrittura atomica degli archivi)');
    } else {
        esito('guasto', 'Scrittura su data/', 'il test di scrittura o di rename e\' fallito',
            'Gli archivi non possono essere aggiornati in sicurezza.');
    }
}

// --- lock fra esecuzioni ---
if (function_exists('flock')) {
    esito('ok', 'flock()', 'disponibile (impedisce a due raccolte sovrapposte di sovrascriversi)');
} else {
    esito('avviso', 'flock()', 'non disponibile',
        'Due raccolte lanciate insieme potrebbero cancellarsi le voci a vicenda. Evitare cron sovrapposti.');
}

// --- esposizione sul web ---
// Il progetto e' pensato per essere servito dalla propria cartella: se lo e',
// dal web si raggiungono anche i log e la cartella dei test.
if (!$cli) {
    esito('avviso', 'Percorso pubblico', 'stai vedendo questa pagina dal web',
        'Verifica che data/*.log, tests/ e .git non siano raggiungibili dal browser, e togli questo file '
        . 'quando hai finito.');
}

// --- stampa ---
$conta = ['ok' => 0, 'avviso' => 0, 'guasto' => 0];
$simbolo = ['ok' => '  OK   ', 'avviso' => ' AVVISO', 'guasto' => ' GUASTO'];

echo "Verifica ambiente - " . date('Y-m-d H:i:s') . " - " . ($cli ? 'riga di comando' : 'web') . "\n";
echo str_repeat('-', 78) . "\n";
foreach ($esiti as $e) {
    $conta[$e['stato']]++;
    printf("[%s] %-26s %s\n", $simbolo[$e['stato']], $e['titolo'], $e['dettaglio']);
    if ($e['conseguenza'] !== '') {
        foreach (explode("\n", wordwrap($e['conseguenza'], 68)) as $riga) {
            echo "           -> $riga\n";
        }
    }
}
echo str_repeat('-', 78) . "\n";
printf("%d ok, %d avvisi, %d guasti\n", $conta['ok'], $conta['avviso'], $conta['guasto']);

if ($conta['guasto'] > 0) {
    echo "\nCi sono guasti da risolvere: il progetto non funzionerebbe cosi'.\n";
} elseif ($conta['avviso'] > 0) {
    echo "\nNessun guasto. Gli avvisi non impediscono la pubblicazione del sito,\n";
    echo "ma vanno letti: alcuni riguardano la raccolta automatica dei dati.\n";
} else {
    echo "\nAmbiente adatto: si puo' procedere con docs/DEPLOY.md.\n";
}

exit($conta['guasto'] > 0 ? 1 : 0);
