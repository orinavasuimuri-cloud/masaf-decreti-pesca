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
 * Quanto un fetcher aspetta il proprio turno prima di arrendersi, e ogni quanto
 * ritenta. Cinque minuti coprono con abbondanza il giro piu' lento (bandi_fetcher
 * con le sue 42 fonti sta sotto i due minuti) senza arrivare all'attesa
 * illimitata, che e' proprio quella che lock_acquisisci() evita di proposito.
 */
const LOCK_ATTESA_FETCHER = 300;
const LOCK_INTERVALLO_RITENTATIVO = 5;

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
 * Prende il lock aspettando il proprio turno, ma non oltre $secondiMax.
 *
 * Serve al caso che l'attesa a zero secondi gestisce male: piu' job pianificati
 * che partono nello stesso istante. Non succede solo per orari messi male in
 * crontab — su una macchina spenta all'ora prevista, l'utilita' di pianificazione
 * recupera all'accensione tutte le esecuzioni perdute insieme, e allora il primo
 * fetcher prende il lock e gli altri se ne vanno senza raccogliere niente. Con
 * l'attesa si mettono in coda e il giro si completa lo stesso, con qualche
 * minuto di ritardo.
 *
 * L'attesa resta limitata: superato il tetto si torna false come prima, perche'
 * la ragione per cui lock_acquisisci() non blocca — non accumulare processi
 * fermi dietro a uno impantanato sulla rete — vale ancora.
 *
 * $avvisa, se passato, viene chiamato una volta sola quando si comincia ad
 * aspettare: e' un'informazione utile nel log, ma ripeterla a ogni tentativo lo
 * riempirebbe di righe tutte uguali.
 *
 * @param callable(string):void|null $avvisa
 * @return resource|false
 */
function lock_acquisisci_entro(string $path, int $secondiMax, ?callable $avvisa = null)
{
    $handle = lock_acquisisci($path);
    if ($handle !== false || $secondiMax <= 0) {
        return $handle;
    }

    // lock_acquisisci() torna false anche quando il file non si apre affatto -
    // permessi sbagliati su data/, cartella mancante - e quello non e' un turno
    // da aspettare: aspettarlo vorrebbe dire cinque minuti persi a ogni giro di
    // ogni fetcher, e un log che accusa un'esecuzione concorrente che non
    // esiste. Chi chiama distingue i due casi con lock_apribile().
    if (!lock_apribile($path)) {
        return false;
    }

    if ($avvisa !== null) {
        $avvisa("un'altra esecuzione e' in corso: si attende il proprio turno, al massimo {$secondiMax}s");
    }

    $scadenza = microtime(true) + $secondiMax;
    while (true) {
        $residuo = $scadenza - microtime(true);
        if ($residuo <= 0) {
            return false;
        }
        // L'attesa si accorcia sul residuo invece di sforare il tetto: un
        // fetcher che dichiara di aspettare 300s e ne aspetta 304 renderebbe il
        // tetto una cosa approssimativa proprio quando serve precisa, cioe'
        // quando lo si sta usando per decidere se il giro e' saltato. Percio'
        // usleep e non sleep, che non saprebbe attendere l'ultima frazione.
        usleep((int) round(min((float) LOCK_INTERVALLO_RITENTATIVO, $residuo) * 1000000));
        $handle = lock_acquisisci($path);
        if ($handle !== false) {
            return $handle;
        }
    }
}

/**
 * Se il file del lock si puo' aprire. Serve a separare "occupato da un altro"
 * da "non apribile", che lock_acquisisci() riporta allo stesso modo ma che
 * vanno detti in modo diverso a chi legge il log.
 */
function lock_apribile(string $path): bool
{
    $prova = @fopen($path, 'c');
    if ($prova === false) {
        return false;
    }
    fclose($prova);
    return true;
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
 * Prima di smettere aspetta: $attesaMassimaSecondi finisce a
 * lock_acquisisci_entro(), e il valore predefinito e' quello buono per un
 * fetcher pianificato. Si passa 0 solo per volere il rifiuto immediato.
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
function lock_o_esci(string $path, callable $avvisa, int $attesaMassimaSecondi = LOCK_ATTESA_FETCHER): void
{
    $handle = lock_acquisisci_entro($path, $attesaMassimaSecondi, $avvisa);
    if ($handle === false) {
        // Un lock che non si apre e' un guasto vero, e va detto con un codice
        // d'errore: la ragione per uscire con 0 vale per il turno occupato, che
        // e' normale amministrazione, non per una cartella non scrivibile che
        // altrimenti terrebbe fermo tutto in silenzio.
        if (!lock_apribile($path)) {
            $avvisa("il file del lock non si apre ($path): controlla i permessi su data/");
            exit(1);
        }
        $avvisa("un'altra esecuzione e' in corso: questa si ferma senza toccare gli archivi");
        exit(0);
    }
    register_shutdown_function(static function () use ($handle): void {
        lock_rilascia($handle);
    });
}
