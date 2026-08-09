<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/lock.php';

// --- il lock esclude davvero ---
// Se questi controlli passassero senza escludere nulla non se ne accorgerebbe
// nessuno fino al giorno in cui due fetcher sovrapposti si cancellano a
// vicenda le voci raccolte, quindi si verifica il rifiuto, non solo la presa.

$lockFile = sys_get_temp_dir() . '/masaf_test_lock_' . getmypid() . '.lock';
@unlink($lockFile);

$primo = lock_acquisisci($lockFile);
t_true(is_resource($primo), 'lock: la prima acquisizione su un lock libero deve riuscire');

$secondo = lock_acquisisci($lockFile);
t_eq($secondo, false, 'lock: la seconda acquisizione deve essere rifiutata mentre la prima e\' viva');

lock_rilascia($primo);

$terzo = lock_acquisisci($lockFile);
t_true(is_resource($terzo), 'lock: dopo il rilascio il lock deve tornare disponibile');
lock_rilascia($terzo);

// Il file resta dopo il rilascio: cancellarlo aprirebbe la finestra in cui due
// processi bloccano due file diversi con lo stesso nome.
t_true(file_exists($lockFile), 'lock: il file del lock non va cancellato al rilascio');

// Rilasciare qualcosa che non e' un handle non deve far esplodere il chiamante:
// i fetcher chiamano lock_rilascia() nel percorso di uscita, anche d'errore.
lock_rilascia(false);
t_true(true, 'lock: rilasciare un handle non valido non solleva errori');

@unlink($lockFile);

// --- esclusione fra processi separati ---
// Il controllo sopra usa due handle dello stesso processo. E' il caso vero
// (due esecuzioni distinte di php) che deve funzionare: qui il lock viene
// preso da un sottoprocesso, e si verifica che questo processo non lo ottenga.
$lockFile2 = sys_get_temp_dir() . '/masaf_test_lock_proc_' . getmypid() . '.lock';
@unlink($lockFile2);

$script = sys_get_temp_dir() . '/masaf_test_lock_child_' . getmypid() . '.php';
file_put_contents($script, '<?php
require ' . var_export(__DIR__ . '/../lib/lock.php', true) . ';
$h = lock_acquisisci(' . var_export($lockFile2, true) . ');
if (!is_resource($h)) { fwrite(STDERR, "il figlio non ha preso il lock"); exit(2); }
echo "preso\n";
// Tiene il lock mentre il padre prova a prenderlo.
usleep(2500000);
lock_rilascia($h);
');

$descrittori = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$figlio = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script), $descrittori, $pipe);
if (is_resource($figlio)) {
    // Si aspetta la riga "preso" invece di dormire a caso: senza questa
    // sincronizzazione il controllo diventa una gara fra i due processi e
    // fallirebbe a intermittenza sulle macchine lente.
    $pronto = fgets($pipe[1]);
    t_eq(trim((string) $pronto), 'preso', 'lock: il sottoprocesso deve aver preso il lock prima del controllo');

    $mio = lock_acquisisci($lockFile2);
    t_eq($mio, false, 'lock: un secondo processo non deve ottenere un lock gia\' tenuto');
    lock_rilascia($mio);

    fclose($pipe[1]);
    fclose($pipe[2]);
    proc_close($figlio);

    // Chiuso il figlio, il lock deve essere di nuovo libero.
    $dopo = lock_acquisisci($lockFile2);
    t_true(is_resource($dopo), 'lock: alla fine del processo che lo teneva il lock torna libero');
    lock_rilascia($dopo);
} else {
    t_true(false, 'lock: non e\' stato possibile avviare il sottoprocesso di prova');
}

@unlink($script);
@unlink($lockFile2);

// --- attesa del proprio turno ---
// E' il caso che ha fatto saltare tre giorni di raccolta: la macchina spenta
// all'ora prevista, l'utilita' di pianificazione che al riavvio recupera tutti i
// job insieme, e tre fetcher su quattro che uscivano a mani vuote. Qui si
// verifica che ora si mettano in coda, ma solo entro il tetto dichiarato.

$lockFile3 = sys_get_temp_dir() . '/masaf_test_lock_attesa_' . getmypid() . '.lock';
@unlink($lockFile3);

$libero = lock_acquisisci_entro($lockFile3, 30);
t_true(is_resource($libero), 'lock: con il lock libero l\'attesa non serve e l\'acquisizione riesce');
lock_rilascia($libero);

// Attesa a zero: e' il comportamento di prima, e va conservato perche' e' quello
// che serve a chi lancia un fetcher a mano e vuole sapere subito com'e' andata.
$occupato = lock_acquisisci($lockFile3);
$subito = lock_acquisisci_entro($lockFile3, 0);
t_eq($subito, false, 'lock: con attesa a zero il lock occupato viene rifiutato subito');
lock_rilascia($occupato);

// Il tetto viene rispettato: il figlio tiene il lock piu' a lungo dell'attesa
// concessa, quindi si deve tornare false, e non dopo un tempo qualsiasi.
$script3 = sys_get_temp_dir() . '/masaf_test_lock_attesa_child_' . getmypid() . '.php';
file_put_contents($script3, '<?php
require ' . var_export(__DIR__ . '/../lib/lock.php', true) . ';
$h = lock_acquisisci(' . var_export($lockFile3, true) . ');
if (!is_resource($h)) { fwrite(STDERR, "il figlio non ha preso il lock"); exit(2); }
echo "preso\n";
usleep(6000000);
lock_rilascia($h);
');

$figlio3 = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script3), $descrittori, $pipe3);
if (is_resource($figlio3)) {
    t_eq(trim((string) fgets($pipe3[1])), 'preso', 'lock: il sottoprocesso deve tenere il lock prima della prova di attesa');

    $avvisi = [];
    $inizio = microtime(true);
    $scaduto = lock_acquisisci_entro($lockFile3, 2, static function (string $m) use (&$avvisi): void {
        $avvisi[] = $m;
    });
    $durata = microtime(true) - $inizio;

    t_eq($scaduto, false, 'lock: scaduta l\'attesa senza aver preso il lock si torna false');
    t_true($durata >= 1.0, 'lock: prima di arrendersi si deve aver aspettato davvero');
    // Il margine e' largo perche' sleep() non e' preciso al millisecondo, ma
    // serve a cogliere il caso in cui il tetto venisse ignorato del tutto.
    t_true($durata < 6.0, 'lock: l\'attesa non deve sforare il tetto dichiarato');
    t_eq(count($avvisi), 1, 'lock: chi aspetta lo dice al log una volta sola, non a ogni tentativo');

    fclose($pipe3[1]);
    fclose($pipe3[2]);
    proc_close($figlio3);

    // Chiuso il figlio il lock e' libero: chi aspettava con un tetto capiente
    // lo avrebbe ottenuto, ed e' questo il comportamento che salva il giro.
    $dopoAttesa = lock_acquisisci_entro($lockFile3, 30);
    t_true(is_resource($dopoAttesa), 'lock: liberato il lock, chi ha un\'attesa capiente lo ottiene');
    lock_rilascia($dopoAttesa);
} else {
    t_true(false, 'lock: non e\' stato possibile avviare il sottoprocesso di prova dell\'attesa');
}

@unlink($script3);
@unlink($lockFile3);

// --- lock non apribile ---
// lock_acquisisci() torna false sia per "occupato" sia per "non si apre": se
// l'attesa non distinguesse i due casi, una cartella non scrivibile costerebbe
// cinque minuti a ogni fetcher e il log accuserebbe un'esecuzione inesistente.
$inesistente = sys_get_temp_dir() . '/cartella_che_non_esiste_' . getmypid() . '/lock';
t_eq(lock_apribile($inesistente), false, 'lock: un percorso non apribile deve risultare tale');

$avvio = microtime(true);
t_eq(lock_acquisisci_entro($inesistente, 30), false, 'lock: un percorso non apribile non si acquisisce');
t_true(microtime(true) - $avvio < 2.0, 'lock: su un percorso non apribile non si deve aspettare il turno');

$lockFile4 = sys_get_temp_dir() . '/masaf_test_lock_apribile_' . getmypid() . '.lock';
@unlink($lockFile4);
t_eq(lock_apribile($lockFile4), true, 'lock: un percorso scrivibile deve risultare apribile');
@unlink($lockFile4);
