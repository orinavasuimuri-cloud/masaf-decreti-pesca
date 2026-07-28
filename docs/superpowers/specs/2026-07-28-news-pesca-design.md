# Sezione news sul mondo della pesca — design

Data: 2026-07-28
Progetto: `masaf-decreti-pesca`

## Obiettivo

Aggiungere al sito una sezione di notizie sul mondo della pesca in Italia, alimentata
automaticamente da fonti istituzionali e dalla stampa di settore, senza interferire con il
registro dei decreti — che resta un catalogo curato a mano.

## Contesto e vincoli

Il progetto è un sito PHP servito da `php -S`, senza framework né dipendenze installate.
Lo stato vive in file JSON sotto `data/`. Un task del Task Scheduler esegue `scraper.php`
alle 07:00 con ripetizione ogni 6 ore.

Vincoli dell'installazione PHP 8.3 in uso, verificati con `php -m`:

| Estensione | Stato | Conseguenza sul design |
|---|---|---|
| `openssl` | assente | `file_get_contents()` non apre URL `https://`; il fetch passa da `curl.exe` via `shell_exec`, come già fa `scraper.php` |
| `curl` (estensione) | assente | idem: si usa l'eseguibile di sistema, non l'estensione |
| `mbstring` | assente | niente `mb_convert_encoding`; le conversioni di charset usano `iconv` |
| `iconv` | presente | converte le pagine MASAF da ISO-8859-1 a UTF-8 |
| `simplexml` | presente | parsing degli RSS senza scrivere un parser XML a mano |
| `intl` | assente | nessuna formattazione localizzata delle date; si usa `date()` come in `index.php` |

## Fonti

Verificate il 2026-07-28.

| Fonte | Tipo | URL | Esito verifica |
|---|---|---|---|
| Pesce in Rete | RSS | `https://www.pesceinrete.com/feed/` | 200, `application/rss+xml`, 10 item con `pubDate` |
| Assoittica | RSS | `https://www.assoittica.it/feed/` | 200, `application/rss+xml`, 10 item |
| MASAF — Notizie | HTML | `.../ServeBLOB.php/L/IT/IDPagina/9` | 200, 10 notizie, URL "puliti" tipo `masaf.gov.it/<slug>` |
| MASAF — Comunicati stampa | HTML | `.../ServeBLOB.php/L/IT/IDPagina/331` | 200, stessa struttura |

Scartate: `federcoopesca.it/feed/` risponde **403** (blocca il fetch automatico);
`ilpesce.net` non risolve in DNS.

Nota sulla resa attesa: le pagine MASAF sono ministeriali a 360° (agricoltura, PAC, olio,
foreste). Al momento della verifica **0 notizie su 10** riguardavano la pesca. La fonte
istituzionale sarà quindi intermittente: è un comportamento atteso, non un guasto.

## Architettura

Due unità isolate che comunicano solo attraverso `data/news.json`, replicando la
separazione già esistente fra `scraper.php` (scrive) e `index.php` (legge).

```
data/news_sources.json   configurazione delle fonti
        |
        v
news_fetcher.php   --curl.exe-->  RSS di settore
        |          --curl.exe-->  pagine MASAF (filtro per parole chiave)
        v
data/news.json     voci normalizzate + stato di salute per fonte
        |
        v
news.php           sola lettura, nessun accesso di rete
```

Conseguenza voluta: un feed che cambia formato rompe il fetcher, mai il sito. Di
`index.php` si toccano solo presentazione e navigazione (estrazione del CSS e link alla
nuova pagina, vedi sotto): la logica di lettura di `catalog.json` e `known.json` resta
invariata, e la home continua a funzionare anche se `news.json` è assente o corrotto.

### `data/news_sources.json`

L'elenco delle fonti è dato, non codice: aggiungere una testata è una riga di JSON.

```json
{
  "sources": [
    { "id": "pesceinrete", "label": "Pesce in Rete", "type": "rss",
      "url": "https://www.pesceinrete.com/feed/", "color": "62 115 104" },
    { "id": "assoittica", "label": "Assoittica", "type": "rss",
      "url": "https://www.assoittica.it/feed/", "color": "92 107 158" },
    { "id": "masaf-notizie", "label": "MASAF · Notizie", "type": "masaf",
      "url": "https://www.masaf.gov.it/flex/cm/pages/ServeBLOB.php/L/IT/IDPagina/9",
      "color": "156 122 46",
      "keywords": ["pesca", "pescher", "ittic", "acquacolt", "mollusch",
                   "vongol", "tonno", "FEAMPA", "GSA", "marittim"] }
  ]
}
```

`type` seleziona il parser: `rss` via SimpleXML, `masaf` via DOMDocument + XPath.
`keywords`, se presente, filtra i titoli; le fonti di settore ne fanno a meno perché già
tutte in tema.

### `data/news.json`

```json
{
  "_meta": {
    "last_run": "2026-07-28T18:00:00+02:00",
    "sources": {
      "pesceinrete": { "last_ok": "2026-07-28T18:00:00+02:00",
                       "last_error": null, "consecutive_failures": 0 }
    }
  },
  "items": [
    { "id": "<sha1 dell'URL>", "source": "pesceinrete",
      "title": "…", "url": "https://…", "date": "2026-07-28T06:08:09+00:00",
      "summary": "…" }
  ]
}
```

Ogni voce ha la stessa forma qualunque sia l'origine. `id` è lo SHA-1 dell'URL
normalizzato ed è la chiave di deduplica: il fetcher può girare ogni 6 ore senza
accumulare doppioni. "Normalizzato" significa: spazi rimossi, fragment `#…` eliminato e
parametri di tracciamento `utm_*` scartati — senza questo, lo stesso articolo ricondiviso
con una query string diversa produrrebbe un secondo `id` e comparirebbe due volte.

`summary` è la `description` dell'RSS ripulita dai tag e troncata a ~200 caratteri. Si
conservano solo titolo, data, estratto e link alla fonte — mai il testo integrale,
trattandosi di contenuti di terzi.

## Comportamento in caso di errore

1. **Isolamento per fonte.** Fetch e parsing di ogni sorgente in `try/catch`: una che
   fallisce non impedisce alle altre di aggiornarsi.
2. **Merge, mai replace.** Le voci già presenti restano anche se la loro fonte non
   risponde. Un feed giù non deve far sparire le notizie del giorno prima. Se cadono
   tutte le fonti, `news.json` non viene riscritto.
3. **Salute visibile.** `news.php` mostra un avviso quando una fonte è ferma da oltre
   48 ore, altrimenti un feed morto diventa indistinguibile da un periodo di calma.
4. **Exit code.** `0` se almeno una fonte è andata a buon fine, `1` se sono cadute
   tutte: il guasto totale si legge in `LastTaskResult` del Task Scheduler.
5. **Encoding.** `json_encode` restituisce `false` su UTF-8 non valido; scritto senza
   controllo produrrebbe un file vuoto. Ogni stringa passa da
   `iconv($from, 'UTF-8//IGNORE', …)` e il valore di ritorno di `json_encode` viene
   verificato prima di toccare il file su disco.
6. Timeout 25s per fonte e User-Agent identificativo, come in `scraper.php`.

## Conservazione e date

Si conservano **al massimo 120 voci in totale, o 90 giorni**, quello che scatta prima
(~50 KB). Il limite è complessivo, non per fonte: una testata molto prolifica può quindi
occupare gran parte della lista, il che è accettabile perché l'ordinamento è cronologico
e il filtro per fonte resta disponibile in pagina.

Le date sono normalizzate a ISO 8601 nel file per l'ordinamento e mostrate `dd/mm/yyyy`
in pagina: gli RSS le forniscono in RFC-2822, il MASAF come `dd/mm/yyyy` nel markup. Se
una data non è interpretabile si usa l'istante di primo rilevamento della voce, così un
formato inatteso non fa sparire la notizia né la manda in cima all'elenco.

## Pagina `news.php`

Riusa l'impianto grafico di `index.php`: chip di filtro per fonte (stesso meccanismo dei
chip per anno già presenti), lista cronologica con badge colorato della fonte, data,
titolo linkato all'originale ed estratto. Link reciproci fra le due pagine
nell'intestazione.

Miglioramento mirato incluso: le ~90 righe di `<style>` oggi inline in `index.php`
vengono estratte in `assets/style.css`, incluso da entrambe le pagine, per non tenerne
due copie allineate a mano. Intervento circoscritto, non un refactoring generale.

## Schedulazione

Task del Task Scheduler **separato** da quello dei decreti, ogni 6 ore. Tenerli distinti
evita che un feed rotto sporchi il `LastTaskResult` del controllo decreti, che è la
funzione critica del progetto.

## Verifiche

Il progetto non ha una test suite. Le verifiche sono manuali ed eseguibili:

1. Fetcher a freddo → `news.json` creato, voci > 0 da almeno due fonti
2. Doppia esecuzione consecutiva → nessun duplicato, conteggio stabile
3. Fonte con URL volutamente rotto → le altre si aggiornano, exit `0`, avviso in pagina
4. Tutte le fonti irraggiungibili → exit `1`, `news.json` intatto
5. Accenti (`è`, `à`, `°`) corretti nel JSON e a schermo — il punto più fragile
6. `news.php` risponde 200 anche con `news.json` assente (prima installazione)

## Fuori ambito

- Nessuna revisione manuale delle news: pubblicazione diretta. Il catalogo dei decreti
  continua a passare da `pending_review`, e la differenza è dichiarata all'utente.
- Nessun archivio storico oltre i 90 giorni, nessuna ricerca full-text, nessuna
  riscrittura o riassunto automatico dei contenuti di terzi.
