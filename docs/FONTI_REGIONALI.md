# Fonti regionali: cosa è leggibile e cosa no

Ricognizione del 12 agosto 2026 su tutte e 18 le pagine FEAMPA regionali
configurate in `data/bandi_regioni.json`. Serve a non rifare il giro: la
risposta breve è che **non esiste un formato comune**, e che scrivere quindici
parser sarebbe stato sbagliato.

## In esercizio

| Regione | Come | Resa al momento della verifica |
| --- | --- | --- |
| Basilicata | feed RSS del sito FEAMPA dedicato | 10 voci su 10 in tema, nessun filtro necessario |
| Campania | feed generale della Regione, filtrato | 1 voce su 50 |
| Campania | **tabella HTML dell'archivio bandi** | 8 bandi, con estremi del decreto |
| Calabria | feed generale della Regione, filtrato | 2 voci raccolte finora |
| Lazio | feed generale (LazioEuropa), filtrato | 0 finora: la pesca ci passa di rado |
| Sicilia | feed generale della Regione, filtrato | 0 finora |

La Campania è l'unica con due fonti: il feed della Regione prende le notizie, la
tabella prende i bandi con i decreti. Sono complementari.

## Perché le altre no

**Il calendario è un PDF allegato** — Abruzzo, Emilia-Romagna, Liguria, Molise,
Piemonte, Sardegna, Sicilia, Toscana. La pagina web è solo l'involucro: il
contenuto sta in file tipo *"Calendario Avvisi e Bandi FEAMPA aggiornato al 30
giugno 2026"*. Leggerli richiederebbe l'estrazione di testo dai PDF, che il
progetto non fa da nessuna parte, e quei documenti cambiano impaginazione a ogni
revisione.

**L'elenco è costruito dal browser** — Veneto, Friuli-Venezia Giulia, Marche,
Lombardia, Umbria. Il caso limite è il Veneto: l'indirizzo è
`feampa.regione.veneto.it/bandi`, il titolo è *"Bandi e Graduatorie"*, ma con
`curl` arriva una pagina vuota perché le voci vengono caricate via JavaScript.
Servirebbe un browser vero, che questo progetto non ha e che complicherebbe
l'installazione sul server.

**Il portale non risponde** — Calabria. Il sito del dipartimento agricoltura
risponde agli header ma va in timeout sul contenuto: probabilmente una
protezione anti-bot. La regione resta coperta dal feed generale, che funziona.

## Valutata e non fatta: la Puglia

`regione.puglia.it/web/feampa-21-27` espone schede con titoli di bandi veri
(*"Approvato il Bando pubblico per gli investimenti destinati a rafforzare la
competitività…"*). È tecnicamente leggibile, ma sono **notizie sui bandi**, non
un archivio: niente estremi del decreto, niente struttura a colonne, e il titolo
è una frase giornalistica che cambia forma da una voce all'altra.

Meno affidabile del feed generale che già usiamo per altre regioni, e più
fragile da mantenere. Se un giorno la Puglia pubblicasse un feed, quello sarebbe
preferibile a qualunque parser scritto su quelle schede.

## Come sono state provate le fonti

Ogni indirizzo è stato scaricato davvero e analizzato per: presenza di un feed
dichiarato, tabelle e righe, allegati PDF, e quante volte la pagina parla di
bandi. I feed candidati sono stati passati a `news_parse_rss()` e filtrati con
le parole chiave del progetto, contando le voci effettivamente in tema — non
basta che un feed esista, deve anche portare qualcosa.

Attenzione ai falsi positivi nel filtro: `marittim` da solo prendeva *"Isole
minori, collegamenti marittimi"*, ed è stato sostituito con `affari marittimi`.
Resta un limite noto: le parole chiave sono sottostringhe senza confine di
parola, quindi `pesca` corrisponde dentro **Pescara**. Innocuo per le regioni
attualmente configurate, da tenere presente se si aggiungesse l'Abruzzo.
