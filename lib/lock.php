<?php
declare(strict_types=1);

/**
 * Mutua esclusione fra le esecuzioni dei fetcher.
 *
 * Ogni fetcher fa lo stesso giro: carica un archivio JSON, lo modifica in
 * memoria, lo riscrive intero. La scrittura e' atomica (file temporaneo piu'
 * rename), quindi un lettore non vede mai un file a meta'; ma due esecuzioni
 * sovrapposte leggono entrambe lo stesso stato di partenza e la seconda a
 * scrivere cancella quello che ha aggiunto la prima. L'atomicita' protegge dal
 * file troncato, non dall'aggiornamento perso.
 *
 * Non capita solo per errore umano: un job pianificato che accumula ritardo si
 * sovrappone a quello successivo da solo, e gazzetta_fetcher.php scrive in
 * data/bandi.json, lo stesso file di bandi_fetcher.php.
 *
 * Il lock e' unico per tutti i fetcher invece che uno per archivio: sono
 * processi brevi che girano da cron a intervalli lunghi, serializzarli non
 * costa nulla, e un lock solo non puo' produrre l'abbraccio mortale che due
 * presi in ordine diverso renderebbero possibile.
 */

/**
 * Prende il lock senza aspettare. Restituisce l'handle da passare a
 * lock_rilascia(), oppure false se un'altra esecuzione lo tiene gia'.
 *
 * Non bloccante per scelta: un fetcher che aspetta il suo turno finirebbe per
 * accodarsi a un'esecuzione bloccata sulla rete, e col passare delle ore
 * avremmo una pila di processi fermi invece di un avviso nel log.
 *
 * @return resource|false
 */
function lock_acquisisci(string $path)
{
    // 'c' apre in scrittura senza troncare: il file del lock non ha contenuto,
    // conta solo la sua esistenza come appiglio per flock(). Troncarlo (con
    // 'w') non romperebbe nulla oggi, ma renderebbe la open distruttiva per
    // chiunque in futuro ci scrivesse dentro il pid.
    $handle = @fopen($path, 'c');
    if ($handle === false) {
        return false;
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return false;
    }
    return $handle;
}

/**
 * Rilascia il lock. Il file resta sul disco: cancellarlo aprirebbe una finestra
 * in cui un altro processo ha gia' aperto lo stesso percorso che noi stiamo per
 * rimuovere, e si ritroverebbe a bloccare un file scollegato dalla directory,
 * cioe' un lock che non esclude nessuno.
 *
 * @param resource|false $handle
 */
function lock_rilascia($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Quello che fanno tutti i fetcher in testa: prendere il lock o smettere.
 *
 * Esce con 0 e non con 1: trovare il lock occupato non e' un guasto ma il
 * funzionamento previsto, e un codice d'errore qui riempirebbe di allarmi la
 * posta di chi ha pianificato il job ogni volta che due esecuzioni si sfiorano.
 * Il messaggio nel log resta, ed e' li' che si guarda se le raccolte sembrano
 * saltare.
 *
 * Il rilascio e' affidato a register_shutdown_function perche' i fetcher
 * escono da piu' punti: legarlo a ogni exit() vorrebbe dire dimenticarselo al
 * primo che se ne aggiunge. Il sistema operativo lo rilascerebbe comunque alla
 * fine del processo, ma lasciarlo implicito renderebbe il codice muto su una
 * cosa che invece conta.
 *
 * @param callable(string):void $avvisa
 */
function lock_o_esci(string $path, callable $avvisa): void
{
    $handle = lock_acquisisci($path);
    if ($handle === false) {
        $avvisa("un'altra esecuzione e' in corso: questa si ferma senza toccare gli archivi");
        exit(0);
    }
    register_shutdown_function(static function () use ($handle): void {
        lock_rilascia($handle);
    });
}
