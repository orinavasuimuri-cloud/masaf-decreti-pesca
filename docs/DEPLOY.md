# Pubblicazione del sito

Guida per chi amministra il server. Chi ha scritto il progetto non ha accesso a
quella macchina: qui c'è tutto il necessario, comprese le cose che di solito si
scoprono a installazione finita.

## Che cos'è

Tre pagine web che raccolgono e mostrano provvedimenti e bandi sulla pesca
professionale, da fonti pubbliche (MASAF, Gazzetta Ufficiale, portali
regionali, GAL della pesca).

- **`index.php`** — registro dei decreti
- **`bandi.php`** — bandi per regione
- **`news.php`** — rassegna stampa

Cinque programmi separati, da eseguire a intervalli, scaricano i dati e li
scrivono in `data/*.json`. Le pagine leggono quei file: non escono mai in rete
loro stesse.

## Cosa non serve

Vale la pena dirlo subito, perché toglie di mezzo metà delle domande:

- **niente database** — gli archivi sono file JSON in `data/`
- **niente Composer**, nessuna libreria di terze parti
- **niente Node.js** per il sito (serve solo per i test del JavaScript, che non
  girano in produzione)
- **niente scrittura da parte del web server**: le pagine leggono soltanto. A
  scrivere sono i programmi di raccolta, che girano da riga di comando.

## Requisiti

| Requisito | Perché |
| --- | --- |
| PHP 8.0 o superiore (consigliato 8.1+) | il codice usa sintassi PHP 8 |
| Estensioni `dom`, `simplexml`, `json`, `iconv` | lettura di pagine e feed, archivi |
| Un modo di uscire in rete (vedi sotto) | solo per i programmi di raccolta |
| Scrittura su `data/` per l'utente che esegue il cron | archivi, log e lock |
| Possibilità di pianificare comandi (cron) | aggiornamento automatico |

`mbstring` **non** serve: il codice usa `iconv` apposta per non dipenderne.

### L'uscita in rete

Va bene **una qualsiasi** di queste tre, e non serve dire al progetto quale:

1. estensione `curl` di PHP
2. `openssl` attivo (wrapper `https://`)
3. `shell_exec` attivo più l'eseguibile `curl`

`lib/rete.php` prova cosa c'è e usa la prima disponibile, in quest'ordine.
`shell_exec` viene per ultima proprio perché è quella che si preferisce tenere
disattiva: **non c'è alcun bisogno di riabilitarla** se esiste una delle altre
due. `check_ambiente.php` dice quale verrà usata.

Se **nessuna** delle tre è disponibile il sito resta pubblicabile — le pagine
leggono solo i file in `data/` — ma i dati vanno prodotti altrove e caricati a
mano. Basta abilitarne una qualsiasi per evitarlo.

## Passo 1 — verificare il server

Prima di ogni altra cosa, dalla cartella del progetto:

```bash
php check_ambiente.php
```

Dice in chiaro cosa funzionerà e cosa no, ed esce con codice 1 se trova
qualcosa di bloccante. **Va lanciato due volte**: da riga di comando e aprendolo
nel browser. Sugli hosting condivisi la configurazione di PHP per la CLI e
quella per il web sono spesso diverse, ed è la CLI a contare per la raccolta.

Se segnala guasti, fermati lì: risolverli dopo costa di più.

**Quando hai finito, togli `check_ambiente.php` dal server**: racconta com'è
fatta la macchina, che è esattamente ciò che serve a chi cerca un modo per
entrarci.

## Passo 2 — installare

Il progetto è su GitHub. Se hai accesso al repository:

```bash
git clone https://github.com/orinavasuimuri-cloud/masaf-decreti-pesca.git
```

Altrimenti va bene una copia dei file per FTP: non c'è nulla da compilare.

Il sito può stare in una sottocartella o in un sottodominio, indifferentemente.
Non ci sono percorsi assoluti nel codice.

## Passo 3 — permessi

L'utente che esegue il cron deve poter scrivere in `data/`. Il web server non
ne ha bisogno.

```bash
chmod u+rwX data
```

Se cron e web server girano con utenti diversi — succede spesso — assicurati
che il web server possa almeno **leggere** `data/*.json`.

## Passo 4 — cosa non esporre

Se il progetto è servito dalla sua stessa cartella, dal browser si raggiunge
anche ciò che non deve essere pubblico. Da bloccare:

| Percorso | Perché |
| --- | --- |
| `data/*.log` | log di esecuzione |
| `data/.fetch.lock` | file di lock |
| `.git/` | l'intera storia del progetto |
| `tests/`, `docs/`, `graphify-out/` | materiale di sviluppo |
| `check_ambiente.php` | descrive il server |

I file `.json` in `data/` **devono** restare leggibili: sono i dati che le
pagine mostrano.

Con Apache, un `.htaccess` nella radice:

```apache
RedirectMatch 404 /\.git
RedirectMatch 404 /data/.*\.log$
RedirectMatch 404 /data/\.fetch\.lock$
RedirectMatch 404 /(tests|docs|graphify-out)/
```

Con nginx, un `location ~ ^/(\.git|tests|docs|graphify-out)/ { return 404; }` e
uno per i log.

La soluzione più pulita, se puoi, è puntare la document root a una cartella che
contiene solo `*.php`, `assets/` e `data/`, tenendo il resto fuori.

## Passo 5 — pianificare la raccolta

Cinque programmi, tutti da lanciare come `php <nome>` dalla cartella del
progetto. Nessuno accetta argomenti.

| Programma | Cosa raccoglie | Ogni quanto |
| --- | --- | --- |
| `scraper.php` | decreti dall'indice MASAF | ogni 6 ore |
| `gazzetta_fetcher.php` | sommari della Gazzetta Ufficiale, e i fascicoli saltati | una volta al giorno |
| `news_fetcher.php` | notizie da stampa e istituzioni | ogni 6 ore |
| `bandi_fetcher.php` | bandi regionali e GAL (42 fonti, il più lento) | due volte al giorno |
| `check_allegati.php` | controllo di integrità del catalogo | una volta a settimana |

Esempio di crontab, con l'accortezza spiegata sotto:

```cron
0  */6 * * *  cd /percorso/del/progetto && php scraper.php
20 6  * * *  cd /percorso/del/progetto && php gazzetta_fetcher.php
40 */6 * * *  cd /percorso/del/progetto && php news_fetcher.php
0  7,19 * * *  cd /percorso/del/progetto && php bandi_fetcher.php
0  5  * * 0  cd /percorso/del/progetto && php check_allegati.php
```

**Gli orari sono sfasati di proposito.** I programmi si escludono a vicenda con
un lock su `data/.fetch.lock`, che impedisce a due raccolte sovrapposte di
cancellarsi le voci a vicenda. Chi trova il lock occupato aspetta il proprio
turno per un massimo di cinque minuti (`LOCK_ATTESA_FETCHER` in `lib/lock.php`) e
solo dopo rinuncia, scrivendolo nel log: pianificare tutto alla stessa ora non fa
più perdere raccolte, ma resta una cattiva idea, perché l'ultimo della fila parte
con l'attesa di tutti quelli davanti.

L'attesa serve soprattutto alle macchine che non sono sempre accese. Se il
computer è spento all'ora prevista, sia cron con `anacron` sia l'Utilità di
pianificazione di Windows recuperano all'avvio le esecuzioni perdute, e le fanno
partire **tutte insieme**: senza attesa, il primo lavora e gli altri escono a mani
vuote. È successo davvero, e per tre giorni notizie e bandi non si sono aggiornati
mentre i log dicevano soltanto «un'altra esecuzione è in corso».

Il fuso orario lo impostano i programmi da soli (`Europe/Rome`): non serve
configurare nulla, e le date restano giuste anche se il server è su UTC.

### Su Windows

Non c'è un crontab, e la pianificazione finirebbe per esistere solo dentro il
sistema, invisibile a chi riprende il progetto. Lo script
`tools/pianifica_windows.ps1` registra gli stessi cinque job nell'Utilità di
pianificazione, con gli orari della tabella qui sopra:

```powershell
pwsh -File tools\pianifica_windows.ps1 -WhatIf   # mostra cosa farebbe
pwsh -File tools\pianifica_windows.ps1           # crea o riallinea i job
```

È idempotente: rilanciarlo dopo aver cambiato gli orari nello script riallinea i
job esistenti senza duplicarli. Trova `php.exe` nel `PATH`, oppure glielo si passa
con `-Php`.

### Codici di uscita

`0` è successo, `1` è guasto. Vale la pena far arrivare gli errori da qualche
parte, per esempio con `MAILTO` in cima al crontab: un programma che esce con
`1` ha una ragione, e la scrive nel proprio log dentro `data/`.

## Passo 6 — verificare

Nell'ordine:

```bash
php check_ambiente.php     # nessun guasto
php tests/run.php          # deve dire "625 passati, 0 falliti"
php gazzetta_fetcher.php   # una raccolta vera, la più veloce
```

Poi apri le tre pagine nel browser. Su ciascuna, in alto, c'è una riga con
l'ora dell'ultimo aggiornamento e i totali: se i numeri ci sono, il giro
funziona.

Se un archivio fosse illeggibile, le pagine **lo dicono** con un riquadro di
avviso invece di mostrarsi vuote. Una pagina che si apre senza avvisi e senza
dati significa che non è ancora stata fatta nessuna raccolta, non che qualcosa
si è rotto.

## Quando qualcosa non va

I log stanno in `data/`, uno per programma: `scraper.log`, `news.log`,
`bandi.log`, `gazzetta.log`, `allegati.log`. Sono in ordine cronologico e
scrivono anche quando tutto va bene, quindi il silenzio è già un'informazione.

| Sintomo | Dove guardare |
| --- | --- |
| una pagina mostra un riquadro d'avviso | l'archivio JSON citato è corrotto: ripristinalo da git |
| i dati non si aggiornano più | il log del programma: cerca `ERRORE` |
| il log dice `si attende il proprio turno` | normale: due raccolte si sono sfiorate, la seconda parte appena può |
| il log dice `un'altra esecuzione e' in corso` | il lock è rimasto occupato per oltre cinque minuti: un processo è appeso, verificalo e chiudilo |
| il log dice `il file del lock non si apre` | non è un turno occupato ma un guasto di permessi su `data/`; il programma esce con `1` |
| una fonte sola non aggiorna | normale se temporaneo; le pagine segnalano da sole le fonti ferme da giorni |
| `ATTENZIONE, non visti i fascicoli` | la Gazzetta è stata saltata per qualche giorno: i numeri restano annotati e il giro dopo vengono riletti uno per uno |
| `da recuperare: 2026/181` | i fascicoli ancora da rileggere, annotati come `anno/numero`. Se ne fanno dieci per volta e non oltre due minuti: se l'elenco non cala di giro in giro, guarda sopra le righe `non recuperato` |
| `la numerazione riparte` | è capodanno: i fascicoli di fine dicembre non ancora letti vanno recuperati a mano, perché la numerazione azzerata non li segnala più |
| `ATTENZIONE, il sommario piu' recente` | la Gazzetta non pubblica da giorni: di solito è la fonte, non noi |

Le stringhe qui sopra sono riportate come compaiono davvero nei log, apostrofi
compresi: cercarle con `grep` funziona.

Un archivio corrotto **non** viene sovrascritto: i programmi si fermano prima,
apposta, per non peggiorare le cose. Ripristinare il file da git e rilanciare è
sempre sicuro.

## Contatti

Per le modifiche al codice rivolgersi a chi ha preparato il progetto. Il
repository su GitHub contiene la storia completa: ogni commit spiega il perché
della modifica, non solo il cosa.

Non serve invece chiedere nulla per l'uscita in rete: il progetto si adatta da
solo a quello che il server offre, e `check_ambiente.php` lo dichiara prima
ancora di installare.
