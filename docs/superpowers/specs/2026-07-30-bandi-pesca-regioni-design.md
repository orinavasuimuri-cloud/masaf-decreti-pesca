# Bandi pesca per regione — design

Data: 2026-07-30
Progetto: `masaf-decreti-pesca`

## Obiettivo

Aggiungere una terza pagina al sito: tutti i bandi che riguardano la pesca, organizzati per
regione, con i bandi aperti in evidenza e l'archivio dei chiusi consultabile. Il registro dei
decreti (`index.php`) e la rassegna news (`news.php`) restano invariati.

La pagina risponde a due domande diverse: *cosa scade adesso* (vista trasversale per scadenza)
e *cosa finanzia la mia regione* (vista per territorio, con l'archivio dei bandi passati).

## Contesto e vincoli

Sito PHP servito da `php -S`, senza framework né dipendenze. Lo stato vive in file JSON sotto
`data/`. I vincoli dell'installazione PHP 8.3 sono quelli già accertati nel design delle news
(`2026-07-28-news-pesca-design.md`) e valgono identici qui: `openssl`, `curl` (estensione) e
`mbstring` assenti — quindi fetch via `curl.exe` in `shell_exec` e conversioni con `iconv`;
`simplexml` presente per gli RSS; `intl` assente, quindi le date italiane si convertono con una
mappa dei mesi scritta a mano.

## Perimetro

Deciso con il committente: **bandi aperti e archivio dei chiusi**, perimetro largo (FEAMPA
regionale, bandi MASAF nazionali, avvisi regionali propri dove intercettabili, e i FLAG). I FLAG
che espongono un feed RSS contribuiscono voci come le fonti istituzionali (vedi "Fonti FLAG"
sotto, aggiunte il 2026-07-31); i FLAG senza feed restano un rimando ai loro siti.

## Fonti

Tutte verificate il 2026-07-30 con richieste reali, non per via documentale.

### Fonte primaria dei dati

| Fonte | Tipo | Esito verifica |
|---|---|---|
| `feampabandionline.it` | WordPress, REST + HTML | 147 bandi; regioni modellate come **categorie** (20 regioni + `Bandi MASAF Nazionali`), stato come categoria `Terminato` (137 voci → **10 aperti**) |

Gestita da Consorzio Mediterraneo Scrl con Legacoop Agroalimentare: **fonte privata, non
istituzionale**. È l'unica che copre tutte le regioni con dati confrontabili, e il fatto va
dichiarato in pagina.

Cosa espone e cosa no, verificato endpoint per endpoint:

- `GET /wp-json/wp/v2/posts` → id, data, link, categorie; `X-WP-Total: 147`, paginabile. **Stabile.**
- `GET /wp-json/wp/v2/categories` → le 20 regioni con slug e conteggio, più `terminato` (id 45).
- I **campi custom non sono esposti**: `content` è vuoto nell'API e `description`/`content:encoded`
  sono vuoti nel feed RSS, perché il sito è costruito con Divi. Il feed dà solo titolo, link,
  data e categorie.
- `GET /regione/<slug>/` e `/regione/<slug>/page/N/` → **qui stanno i dati utili**: priorità
  FEAMPA, *Scopo contributo*, codice di intervento, regione, data di pubblicazione, data di
  scadenza, note di proroga e link al decreto regionale ufficiale. 10 voci per pagina
  (verificato su Sicilia: 10 + 8 = 18), quindi ~17 richieste per la copertura completa.
- Il post type `project` esiste ma è vuoto (`X-WP-Total: 0`), e non ci sono tag: nessun dato
  nascosto altrove.

### Fonti istituzionali secondarie

| Fonte | Tipo | Esito verifica |
|---|---|---|
| `feampa.regione.basilicata.it/feed/` | RSS | **9 item su 9 in tema**: è un sito WordPress dedicato al solo FEAMPA. Unica Regione machine-readable |
| `regione.calabria.it/feed/` | RSS + filtro keyword | 2 item su 10 in tema, tra cui un avviso regionale proprio (imprese della pesca colpite dal ciclone) fuori dal perimetro FEAMPA |
| `lazioeuropa.it/feed/` | RSS + filtro keyword | 0 su 10 al momento della verifica: fonte intermittente, come già accettato per le pagine MASAF nelle news |

Hanno due funzioni: intercettare gli avvisi regionali propri che il FEAMPA non contempla, e
**verificare la copertura** dell'aggregatore privato — la Basilicata pubblica 9 bandi dove
l'aggregatore le attribuisce 4, e lo scarto è un dato che vale mostrare.

Sono feed di sito, non di elenco bandi: danno titolo, data e link, mai la scadenza. Le voci che
ne derivano sono quindi **segnalazioni**, non schede di bando, e la pagina le distingue.

### Fonti FLAG (aggiunte il 2026-07-31)

Dei 29 FLAG censiti in `data/bandi_regioni.json`, **18 espongono un feed RSS verificato con una
richiesta reale** (WordPress `/feed/`, uno con feed dedicato ai soli bandi). Contribuiscono voci
con lo stesso trattamento delle fonti istituzionali — sono feed di sito, non elenchi di bandi,
quindi niente scadenza — ma con origine propria (`flag`, invece di `istituzionale`) perché la
pagina deve poter dire **quale** FLAG le ha pubblicate, non solo che sono un canale regionale:
diversamente da Basilicata/Calabria/Lazio (un feed a testa), qui più FLAG condividono la stessa
sezione di regione (la Sicilia ne ha sei).

| Regione | FLAG | Voci in tema (fetch del 2026-07-31) |
|---|---|---|
| Abruzzo | GAL Pesca Abruzzo | 3 |
| Campania | GAL Pesca Magna Graecia | 10 |
| Campania | GAL Approdo di Ulisse | 9 |
| Campania | GAL Parthenope | 3 |
| Emilia-Romagna | GALPA Costa Emilia-Romagna | 6 |
| Lazio | GAL Pesca Lazio | 7 |
| Liguria | GAL FISH Liguria | 5 |
| Molise | GAL MARE Molise Costiero | 6 |
| Puglia | GAL Terre di Mare | 3 |
| Puglia | GAL Terra dei Trulli e di Barsento | 4 |
| Sicilia | GALP dei Golfi | 6 |
| Sicilia | GALP Golfo di Termini Imerese | 4 |
| Sicilia | GAL Pesca Trapanese | 2 |
| Sicilia | FLAG Il Sole e l'Azzurro | 10 |
| Sicilia | FLAG Riviera Jonica Etnea | 2 |
| Sicilia | GAC dei Due Mari | 5 |
| Toscana | GALPA Toscana | 8 |
| Veneto | FLAG Veneziano - VeGAL | 2 |

I restanti 11 FLAG (tra cui GAC Golfo di Patti, GAL Blu, GALPA Chioggia e Delta del Po, GAL Pesca
Gargano Mare, FLAG Sardegna Sud Occidentale, FLAG Nord Sardegna, GALPA FVG, CLLD GAL Pesca Marche,
GAL Pesca La Cittadella del Sapere e FLAG Borghi Marinari dello Ionio/GALPA MariCal) non hanno un
feed raggiungibile: restano link cliccabili in testa alla sezione di regione, senza scraping di
markup eterogenei.

**Correzione URL Campania**: i tre FLAG campani in `data/bandi_regioni.json` puntavano a schede
sul sito della Regione (`agricoltura.regione.campania.it/FEAMPA/FLAG/...`), non ai siti propri.
Corretti con una ricerca dedicata: GAL Pesca Magna Graecia (`galpescamagnagraecia.it`, con feed
dedicato `/bandi/feed/`), GAL Approdo di Ulisse (`flagapprododiulisse.it/feed/`) e GAL Parthenope
(`galparthenope.it/feed/`) hanno tutti un sito proprio WordPress con feed valido.

**Filtro keyword**: i titoli reali dei FLAG quasi mai contengono la parola "FEAMPA" — sono avvisi
di attuazione delle strategie di sviluppo locale ("Avviso pubblico Azione 1.1.C della SSL GAL
Pesca Lazio", "Bando di Attuazione dell'Azione 4G"). Il filtro usato è quindi più largo di quello
delle fonti istituzionali: `band`, `avviso`, `graduatoria`, `proroga`, `interesse`, `contribut`,
`FEAMPA`. Due scelte non ovvie, verificate sui dati reali dei 18 feed (oltre 170 item):
- **`band` come radice, non `bando`**: molti annunci usano il plurale ("due nuovi bandi per
  investimenti", "presentate 68 domande ai bandi ISL03..."), che la forma singola perderebbe.
- **`interesse` da solo, non la frase "manifestazione di interesse"**: sui feed reali compaiono
  sia "manifestazione **d'**interesse" (apostrofo) sia "manifestazion**i** d'interesse" (plurale),
  varianti che la frase esatta non intercetta. Verificato che "interesse" da solo non introduce
  rumore: su tutti gli item raccolti la parola compare solo in questo contesto.

### Directory di link ufficiali

| Fonte | Contenuto |
|---|---|
| [`Link_OI_Calendari_di_Avvisi_e_Bandi_FEAMPA-27-luglio-2026.pdf`](https://www.feampa.it/root/wp-content/uploads/2026/07/Link_OI_Calendari_di_Avvisi_e_Bandi_FEAMPA-27-luglio-2026.pdf) su `feampa.it` | link ufficiale al calendario bandi di **18 Regioni**, documento MASAF aggiornato al 27/07/2026 |
| Pagina *Bandi GALPA* dell'aggregatore | **29 FLAG** con link diretto alla rispettiva pagina bandi e avvisi; **18** hanno anche un feed RSS interrogato da `bandi_fetcher.php` (vedi "Fonti FLAG") |

Entrambe si leggono una volta e si versionano come dati curati: sono elenchi di link, non
sorgenti da interrogare a ogni esecuzione — il feed di ciascun FLAG, dove esiste, è configurato
invece in `data/bandi_fonti.json` e interrogato a ogni fetch.

### Verificate e scartate

- **I 18 portali regionali degli Organismi Intermedi**: sondati tutti e uno per uno. Nessuno
  espone API o feed dei bandi. Piattaforme Drupal, Liferay, Flex CM, con i calendari pubblicati
  in PDF. I soli tre feed RSS trovati (Basilicata, Calabria, Lazio) sono feed globali di sito e
  sono quelli già presi come fonti secondarie qui sopra. Scrapare 18 markup diversi darebbe una
  fragilità sproporzionata al guadagno.
- **`feampa.it/opportunita/`**: solo i 3-4 avvisi nazionali MASAF, nessun feed, nessun filtro
  per regione. I bandi nazionali arrivano già dall'aggregatore nella categoria dedicata.
- **`acquacoltura.org`, `fasi.eu`, `europafacile.net`**: altri portali privati sullo stesso
  perimetro. Aggiungerebbero ridondanza e un secondo problema di deduplica, non copertura.

## Architettura

Terza unità isolata, identica per forma alle due esistenti: comunica col sito solo attraverso un
file JSON, e nessun accesso di rete avviene durante il rendering della pagina.

```
data/bandi_regioni.json   20 regioni: slug aggregatore, calendario ufficiale, FLAG (curato)
data/bandi_fonti.json     aggregatore (base url, id categoria "Terminato") + feed istituzionali
                          + feed dei 18 FLAG con RSS (aggiunti il 2026-07-31)
        |
        v
bandi_fetcher.php  --curl--> /wp-json/wp/v2/posts       censimento: id, data, regione, stato
                   --curl--> /regione/<slug>/page/N/    campi: scadenza, scopo, codice, link ufficiale
                   --curl--> 3 feed RSS istituzionali    segnalazioni origine "istituzionale" (filtro keyword)
                   --curl--> 18 feed RSS dei FLAG        segnalazioni origine "flag" (filtro keyword, con FLAG di provenienza)
        v
data/bandi.json    voci normalizzate + salute per fonte + copertura per regione
        v
bandi.php          sola lettura
```

Moduli nuovi: `lib/bandi_parser.php` (parsing archivio e campi) e `lib/bandi_store.php` (merge,
stato, salvataggio). Riusano `lib/news_normalize.php` per conversione charset, normalizzazione
URL e hashing (`news_to_utf8`, `news_normalize_url`, `news_item_id`) e `news_parse_rss` per i tre
feed: sono funzioni generiche e già coperte dai test. Il prefisso `news_` resta un residuo
storico; rinominarlo toccherebbe tre file più la suite e non serve a questo lavoro.

Conseguenza voluta, come per le news: se la fonte cambia struttura si rompe il fetcher, mai il
sito. `index.php` e `news.php` non vengono toccati, a parte il link alla nuova pagina nella
topbar.

## Modello dati

### `data/bandi_regioni.json`

Curato a mano una volta, dalle due directory di link. Una voce per regione:

```json
{
  "_meta": { "fonte_calendari": "PDF MASAF 27/07/2026", "verified_on": "2026-07-30" },
  "regioni": [
    {
      "slug": "toscana",
      "nome": "Toscana",
      "calendario_ufficiale": "https://www.regione.toscana.it/feampa-2021-2027/calendario-bandi-e-avvisi",
      "flag": [
        { "nome": "GALPA Toscana", "url": "https://www.galpatoscana.it/bandi-aperti-e-chiusi/" }
      ]
    }
  ]
}
```

I nomi dei FLAG vanno letti a mano dai rispettivi siti: nella pagina GALPA dell'aggregatore i
link sono loghi senza testo alternativo utile, quindi dedurli dal dominio produrrebbe nomi
sbagliati. Due regioni (Trentino-Alto Adige, Valle d'Aosta) esistono come categoria
sull'aggregatore ma non figurano nel PDF MASAF: `calendario_ufficiale` resta `null` e la pagina
mostra la sezione senza link, senza inventarne uno.

### `data/bandi.json`

```json
{
  "_meta": {
    "last_run": "2026-07-30T09:00:00+02:00",
    "fonti": { "aggregatore": { "last_ok": "…", "last_error": null, "consecutive_failures": 0 } },
    "copertura": { "attesi_api": 147, "raccolti": 147, "per_regione": { "toscana": 10 } }
  },
  "items": [
    {
      "id": "<sha1 url normalizzato>",
      "wp_id": 1417,
      "origine": "aggregatore",
      "regioni": ["toscana"],
      "priorita": "2 — Promuovere le attività di acquacoltura sostenibile…",
      "titolo": "Salute e compatibilità ambientale dei prodotti dell'acquacoltura",
      "codice_intervento": "221609",
      "pubblicazione": "2026-06-10",
      "scadenza": "2026-07-15",
      "stato": "chiuso",
      "nota": "Bando prorogato al 15 luglio 2026 ore 13.00",
      "url_fonte": "https://www.feampabandionline.it/2026/06/10/…",
      "url_ufficiale": "https://www301.regione.toscana.it/bancadati/atti/…",
      "dettagli_mancanti": false
    }
  ]
}
```

Tre scelte non ovvie.

**Il titolo non è il titolo del post.** Sull'aggregatore i post si chiamano come la priorità
FEAMPA, quindi 147 voci condividono una dozzina di titoli identici e illeggibili in elenco
("2 — Promuovere le attività di acquacoltura sostenibile e la trasformazione…"). Come titolo si
usa la prima frase dello *Scopo contributo*, e la priorità diventa un'etichetta secondaria.

**Lo stato è calcolato, non copiato.** `scadenza >= oggi` → `aperto`, scadenza passata →
`chiuso`; la categoria `Terminato` della fonte decide solo quando la scadenza manca, e in quel
caso lo stato è `chiuso` se marcata, `da_verificare` altrimenti. Copiare la categoria
significherebbe mostrare come aperto un bando che l'aggregatore non ha ancora marcato.

**Le segnalazioni dai feed istituzionali** hanno `origine: "istituzionale"`, nessuna `scadenza` e
stato `da_verificare`: sono titolo, data e link. Non vanno mostrate come schede di bando.

**Le segnalazioni dai feed dei FLAG** (aggiunte il 2026-07-31) hanno `origine: "flag"`, stessa
forma delle istituzionali (nessuna `scadenza`, stato `da_verificare`), più il campo `fonte_label`
con il nome del FLAG che le ha pubblicate: a differenza dei tre feed istituzionali (un feed per
regione), qui più FLAG condividono la sezione della stessa regione — la Sicilia ne ha sei — e
senza il nome la pagina non potrebbe distinguerli. `bandi_da_feed()` accetta `$origine` e
`$fonteLabel` come parametri opzionali (default `"istituzionale"` e stringa vuota) proprio per
produrre entrambe le forme senza duplicare la funzione.

`id` è lo SHA-1 dell'URL normalizzato, come per le news: il fetcher può girare ogni giorno senza
accumulare doppioni. `wp_id` si conserva per poter riconciliare con l'API anche se la fonte
cambia i permalink.

### Dalle categorie della fonte alla regione

Un post porta più categorie insieme (`[Terminato, Toscana]`), quindi la regione va ricavata per
esclusione: si scartano `terminato` e `senza-categoria`, e ciò che resta è il territorio. Le tre
conseguenze vanno gestite esplicitamente, perché ognuna esiste nei dati reali:

- **`bandi-masaf-nazionali` non è una regione.** Diventa una sezione a sé, mostrata per prima
  subito dopo il riquadro dei bandi aperti, perché riguarda tutte le regioni.
- **Più regioni sullo stesso post**: la voce compare nella sezione di ognuna, con lo stesso `id`.
  Per questo il campo si chiama `regioni` ed è un array, mai una stringa.
- **Nessuna categoria territoriale**: la voce finisce in una sezione *Non attribuiti*, invece di
  essere scartata in silenzio. È il caso in cui la fonte pubblica un bando prima di classificarlo.

Le keyword del filtro sui tre feed istituzionali sono le stesse già in uso per le pagine MASAF in
`data/news_sources.json` (`pesca`, `pescher`, `ittic`, `acquacolt`, `mollusch`, `vongol`, `tonno`,
`FEAMPA`, `GSA`, `marittim`): non c'è motivo di mantenere due liste divergenti dello stesso
concetto. I feed dei FLAG hanno una lista propria (`band`, `avviso`, `graduatoria`, `proroga`,
`interesse`, `contribut`, `FEAMPA`), tarata sui loro titoli reali — vedi "Fonti FLAG" più sopra —
perché il tema è già ristretto alla pesca e il rumore da escludere è di natura diversa (verbali di
CdA, eventi, newsletter) da quello dei feed di sito regionali generalisti.

## Comportamento in caso di errore

Stessa disciplina di `news_fetcher.php`, con una differenza sostanziale: **nessuna potatura**.
Per le news 90 giorni bastano; qui l'archivio dei 137 bandi chiusi è il valore della pagina e si
conserva integralmente.

1. **Best-effort a due livelli.** Se l'API risponde ma il parsing dei campi di una pagina
   archivio fallisce, la voce resta con i dati minimi (regione, data, link) e
   `dettagli_mancanti: true`, invece di sparire.
2. **Isolamento per regione e per fonte.** Una regione che non risponde non impedisce alle altre
   di aggiornarsi; un feed rotto non tocca l'aggregatore.
3. **Merge, mai replace.** Le voci già presenti restano anche se la loro fonte non risponde. Se
   cade tutto, `items` non viene riscritto.
4. **Riconciliazione della copertura.** `X-WP-Total` dell'API viene confrontato con le voci
   raccolte, e i conteggi per categoria con quelli per regione: lo scarto finisce in
   `_meta.copertura` e compare in pagina come avviso di copertura incompleta. Senza questo, una
   paginazione che cambia farebbe perdere bandi in silenzio.
5. **Salute visibile.** Come in `news.php`, avviso in pagina se una fonte è ferma da oltre 7
   giorni — soglia più larga di quella delle news perché i bandi cambiano in settimane.
6. **Exit code** `0` se almeno una regione è stata aggiornata, `1` se sono cadute tutte.
7. **Encoding.** Ogni stringa passa da `iconv(…, 'UTF-8//IGNORE', …)` e il valore di ritorno di
   `json_encode` viene verificato prima di scrivere: su UTF-8 non valido restituisce `false` e
   produrrebbe un file vuoto.
8. Timeout 25s per richiesta e User-Agent identificativo, come in `scraper.php`.

**Carico sulla fonte**: ~17 richieste all'archivio, 2 all'API, 3 ai feed istituzionali e 18 ai feed
dei FLAG (aggiunti il 2026-07-31, ~40 richieste totali), **una volta al giorno**. I bandi cambiano
in settimane, non in ore: un task giornaliero del Task Scheduler, separato da quello dei decreti e
da quello delle news, così un guasto qui non sporca il `LastTaskResult` del controllo decreti, che
resta la funzione critica del progetto.

## Pagina `bandi.php`

Riusa l'impianto grafico esistente, senza nuovi pattern:

- **In cima i bandi aperti**, ordinati per scadenza e trasversali alle regioni, nello stile del
  riquadro `.pending-box` della home. È la risposta a "cosa scade adesso".
- **Chip indice per regione** e chip filtro `Tutti / Solo aperti`, con lo stesso meccanismo dei
  `.yr-chip` già in `index.php` e `news.php`.
- **Una sezione per regione**, con l'archivio ordinato per data discendente. Ogni testa di
  sezione porta il **link al calendario ufficiale della Regione** e i link ai **FLAG** del
  territorio, così il dato dell'aggregatore è sempre verificabile alla fonte. I FLAG con feed
  RSS compaiono anche come voci in elenco (origine `flag`), distinte con "segnalazione dal FLAG
  <nome>, scadenza da verificare alla fonte"; i FLAG senza feed restano solo nel link di testa.
- Ogni voce mostra: stato, scadenza, codice di intervento, titolo, priorità come etichetta, nota
  di proroga, link alla scheda della fonte e, quando c'è, al decreto ufficiale.
- Link reciproci fra le tre pagine nella topbar.

Due dichiarazioni esplicite in pagina, per onestà verso chi legge: la fonte principale è un
**aggregatore privato**, non istituzionale, e in caso di divergenza fa fede il sito della
Regione; le voci `origine: istituzionale` sono **segnalazioni senza scadenza verificata**.

## Verifiche

Test automatici in `tests/`, con il runner esistente (`php tests/run.php`):

1. Parsing di una pagina archivio reale salvata come fixture → numero di voci e campi attesi
2. Date italiane ai bordi: `10 Giugno 2026`, mese sconosciuto, stringa vuota
3. Calcolo dello stato: scadenza futura, passata, assente con e senza categoria `Terminato`
4. Merge non distruttivo: seconda esecuzione senza duplicati, voce esistente aggiornata e non
   duplicata quando cambia il titolo
5. Degrado controllato: pagina archivio illeggibile → voci con `dettagli_mancanti`, mai perse
6. Riconciliazione: `attesi_api` maggiore dei raccolti → scarto registrato in `_meta.copertura`

Verifiche manuali eseguibili:

7. Fetcher a freddo → `bandi.json` creato, ~147 voci, 20 regioni popolate
8. Regione con URL volutamente rotto → le altre si aggiornano, exit `0`, avviso in pagina
9. Tutte le fonti irraggiungibili → exit `1`, `bandi.json` intatto
10. Accenti (`è`, `à`, `°`) corretti nel JSON e a schermo — il punto più fragile
11. `bandi.php` risponde 200 con `bandi.json` assente (prima installazione)
12. *(2026-07-31)* Configurazione dei feed FLAG: ogni fonte in `data/bandi_fonti.json.flag` ha
    id univoco, url https, e la regione dichiarata esiste in `data/bandi_regioni.json`; l'URL del
    FLAG Magna Graecia punta al sito proprio, non più alla scheda sul sito della Regione

## Fuori ambito

- Scraping dei 18 portali regionali e dei PDF dei calendari: verificato che non sono
  machine-readable, e 18 markup diversi darebbero fragilità sproporzionata.
- **Scraping dei 29 siti FLAG** era fuori ambito nella versione originale di questo documento
  (2026-07-30): non è più vero. I 18 FLAG con un feed RSS verificato contribuiscono voci dal
  2026-07-31 (vedi "Fonti FLAG"). Resta fuori ambito solo lo **scraping di markup HTML** dei siti
  FLAG senza feed: i restanti 11 continuano a essere rimandi cliccabili nella scheda della
  regione, per lo stesso motivo per cui i 18 portali regionali non vengono scrapati.
- Notifiche di scadenza, ricerca full-text, download degli allegati dei bandi, riscrittura o
  riassunto automatico dei testi di terzi.
- Revisione manuale prima della pubblicazione: come le news, le voci vanno online direttamente.
  Il catalogo dei decreti resta l'unica sezione curata a mano, e la differenza è dichiarata.
