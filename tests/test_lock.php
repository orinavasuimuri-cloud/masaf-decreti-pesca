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
