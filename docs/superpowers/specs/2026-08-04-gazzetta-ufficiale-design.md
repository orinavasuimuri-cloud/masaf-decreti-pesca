# Gazzetta Ufficiale come fonte istituzionale

Data: 2026-08-04

## Il problema

La pagina dei bandi ha oggi tre origini: l'aggregatore **FEAMPA Bandi Online**, che è
un soggetto privato (Consorzio Mediterraneo con Legacoop Agroalimentare), tre feed
istituzionali regionali e diciotto feed dei FLAG. Manca il canale che in Italia fa
davvero fede: la pubblicazione in gazzetta. Finché la fonte principale è un
aggregatore privato, il sito non ha modo di accorgersi da solo quando l'aggregatore
sbaglia, ritarda o omette.

Questa spec aggiunge la **Gazzetta Ufficiale della Repubblica** come quarta origine e
stabilisce cosa fare dei Bollettini Ufficiali Regionali.

## Cosa si è verificato sul campo (04/08/2026)

Non sono ipotesi: sono risposte reali interrogate durante la progettazione.

**La GU espone RSS veri**, uno per serie, con `Content-Type: application/rss+xml`:

| codice | serie | voci nel numero controllato |
|---|---|---|
| `SG` | Serie Generale | 17 |
| `S1` | 1ª Speciale · Corte Costituzionale | 13 |
| `S2` | 2ª Speciale · Unione Europea | 28 |
| `S3` | 3ª Speciale · Regioni | 8 |
| `S4` | 4ª Speciale · Concorsi | 50 |
| `S5` | 5ª Speciale · Contratti Pubblici | 1 |

URL: `https://www.gazzettaufficiale.it/rss/<codice>`. Attenzione a `S1`: non è la
Serie Generale ma la Corte Costituzionale; la Serie Generale è `SG`.

**Ogni feed è il sommario di un solo numero**, non un archivio interrogabile. La
`<description>` del canale lo dichiara: `Gazzetta Ufficiale - Serie Generale n. 178
del 03-08-2026`.

**Il titolo porta emittente e tipo di atto in forma strutturata**, l'oggetto no:

```
MINISTERO DELL'AGRICOLTURA, DELLA SOVRANITA' ALIMENTARE E DELLE FORESTE - DECRETO 24 giugno 2026
```

L'oggetto sta in `<content:encoded>`, che termina con il codice identificativo
dell'atto fra parentesi:

```
Fondo Alimentare 2026 e 2027. Individuazione dei beneficiari del contributo
economico previsto dall'articolo 1, commi 5 e 6 della legge 30 dicembre 2025
n. 199. (26A03853)
```

Conseguenza vincolante: **il filtro per parole chiave che il progetto usa oggi
guarda solo il titolo** (`bandi_da_feed`, `lib/bandi_parser.php`). Applicato alla GU
non troverebbe mai nulla, perché nel titolo la materia non c'è.

**I BUR non hanno un aggancio comune.** Il BUR Veneto è un'applicazione ASPX senza
feed; `regione.sicilia.it/rss` risponde 404. Non esiste un formato condiviso fra le
venti regioni.

**Validazione del filtro end-to-end.** Il numero 178 della Serie Generale contiene
due decreti MASAF: Fondo Alimentare e avversità atmosferiche in Molise. Entrambi
sono agricoli e il filtro per parole chiave sull'oggetto li scarta correttamente.
Questo dimostra che filtrare per solo emittente produrrebbe rumore: il MASAF governa
agricoltura, foreste e pesca, e la pesca è la minoranza dei suoi atti.

## Decisioni prese

1. **GU via RSS, BUR linkati e non raschiati.** Un parser su misura per ogni regione
   costiera — una quindicina, sulle venti configurate in `data/bandi_regioni.json` —
   si romperebbe in silenzio a ogni restyling. I BUR seguono invece la
   convenzione che il progetto applica già ai FLAG senza feed: collegati in testa
   alla sezione, senza le loro voci in elenco. La pagina dichiara cosa copre e cosa
   no, che è più onesto di una copertura che si degrada senza avvisare.

2. **Smistamento per natura dell'atto.** Bandi, avvisi e graduatorie vanno alla
   pagina dei bandi; i decreti vanno alla coda di revisione del registro. È la
   divisione che il sito già fa, applicata a una fonte nuova.

## Architettura

Tre file nuovi, nessuna modifica invasiva all'esistente:

| file | ruolo |
|---|---|
| `gazzetta_fetcher.php` | rete e orchestrazione: scarica, registra il log, isola le fonti |
| `lib/gazzetta_parser.php` | funzioni pure: parsing, classificazione, filtro. Testabile su fixture |
| `data/gazzetta_fonti.json` | configurazione delle serie da seguire |
| `data/gazzetta.json` | archivio delle voci in tema e coda di revisione |

La divisione rete/funzioni pure ricalca quella già adottata per gli allegati
(`check_allegati.php` più `lib/allegati.php`), per cui il parsing resta verificabile
senza toccare la rete.

Si parte dalla **sola Serie Generale**. Le altre cinque restano una riga di
configurazione, non codice nuovo: `S2` (Unione Europea, dove escono i regolamenti
sulle quote già citati nel catalogo) si aggiunge quando serve senza toccare i
sorgenti.

### `data/gazzetta_fonti.json`

```json
{
  "serie": [
    {
      "id": "gu-sg",
      "label": "Gazzetta Ufficiale · Serie Generale",
      "url": "https://www.gazzettaufficiale.it/rss/SG",
      "keywords": ["pesca", "pescher", "ittic", "acquacolt", "mollusch",
                   "vongol", "tonno", "FEAMPA", "GSA", "marittim"]
    }
  ]
}
```

### `data/gazzetta.json`

```json
{
  "_meta": {
    "last_run": "2026-08-04T15:00:00+02:00",
    "serie": {
      "gu-sg": {
        "ultimo_numero": 178,
        "ultima_data": "2026-08-03",
        "last_ok": "2026-08-04T15:00:00+02:00",
        "errore": null
      }
    }
  },
  "items": {
    "26A03853": {
      "id": "26A03853",
      "serie": "gu-sg",
      "emittente": "MINISTERO DELL'AGRICOLTURA, DELLA SOVRANITA' ALIMENTARE E DELLE FORESTE",
      "tipo_atto": "DECRETO",
      "titolo": "MINISTERO DELL'AGRICOLTURA… - DECRETO 24 giugno 2026",
      "oggetto": "…",
      "url": "https://www.gazzettaufficiale.it/eli/id/2026/08/03/26A03853/SG",
      "numero_gu": 178,
      "data_gu": "2026-08-03",
      "destinazione": "registro",
      "status": "pending_review",
      "first_seen": "2026-08-04"
    }
  }
}
```

L'identificatore è il codice dell'atto (`26A03853`), presente sia in coda
all'oggetto sia nell'URL ELI: è stabile e assegnato dalla fonte, quindi non serve
derivarne uno per hash come fa `news_item_id()`.

## Formati da riconoscere

**Numero e data del fascicolo**, dalla `<description>` del canale:

```
/n\.\s*(\d+)\s+del\s+(\d{2})-(\d{2})-(\d{4})/
```

**Emittente e tipo di atto**, dal titolo: si separa sull'**ultimo** ` - `, non sul
primo. `PRESIDENZA DEL CONSIGLIO DEI MINISTRI - DIPARTIMENTO PER LA TRASFORMAZIONE
DIGITALE - DECRETO 19 marzo 2026` ne contiene due, e spezzare sul primo attribuirebbe
l'atto al dipartimento sbagliato. Quando il separatore manca (`DECRETO LEGISLATIVO 26
giugno 2026, n.138`) l'emittente è vuoto: sono atti dello Stato.

Il tipo di atto è la sequenza di parole maiuscole in testa al segmento finale, fino
alla data: `DECRETO`, `DECRETO LEGISLATIVO`, `ORDINANZA`, `COMUNICATO`, `DELIBERA`.

**Codice dell'atto**, dalla coda dell'oggetto: `/\(([0-9]{2}[A-Z][0-9]{5})\)\s*$/`.
Se manca, si ricava dall'URL ELI; se manca anche lì, la voce viene scartata con un
log, perché senza identificatore stabile ogni run la ripresenterebbe come nuova.

## Filtro e smistamento

**In tema** se le parole chiave della serie compaiono nell'oggetto **o** nel titolo.
Il titolo da solo non basta (vedi sopra), ma va comunque guardato: alcune serie lo
usano per la materia.

**Destinazione**, valutata sull'oggetto:

| condizione | destinazione |
|---|---|
| contiene `bando`, `avviso pubblico`, `graduatoria`, `manifestazione di interesse`, `domande di partecipazione` | `bandi` |
| tutto il resto | `registro` |

Il dubbio va al registro, non ai bandi: il registro ha un cancello umano, la pagina
dei bandi no. `contribut` non è fra i segnali di bando pur essendo frequente: nel
numero 178 compare in un decreto che individua beneficiari, che è un atto, non un
avviso a cui ci si candida. Un segnale così debole manderebbe decreti nella pagina
sbagliata.

Le voci con `destinazione: bandi` entrano in `bandi.json` tramite
`bandi_store_merge()` con fonte `gu-sg` e `origine: 'gazzetta'`, senza scadenza
e con `dettagli_mancanti: true`: lo stesso trattamento delle segnalazioni FLAG, che
è già la convenzione per le voci di cui non si conosce il termine.

## Coda di revisione

Le voci con `destinazione: registro` **non** vanno in `known.json`. Quel file è
indicizzato per IDPagina MASAF ed è di proprietà di `scraper.php`: infilarci voci con
un identificatore di forma diversa romperebbe il contratto fra i due programmi.
Vivono in `data/gazzetta.json` e `index.php` le rende **nello stesso riquadro "da
rivedere"** già presente, con un'etichetta che dice da quale serie arrivano. Il
conteggio in intestazione somma le due code.

## Bollettini regionali

Un campo `bur` per regione in `data/bandi_regioni.json`, accanto a
`calendario_ufficiale` e `flag`, reso in testa alla sezione come gli altri due
(`bandi.php`, intorno alla riga 294). Le regioni senza BUR raggiungibile restano
senza il campo, e in pagina non compare nulla: assente non è vuoto.

## Buchi di copertura

Il feed è il sommario di un numero solo: se il job salta due giorni, quei numeri sono
persi. Il fetcher confronta il numero letto con `_meta.serie.<id>.ultimo_numero` e,
se il salto è maggiore di uno, scrive nel log quali numeri non ha visto. Non li
recupera: dal feed non si possono derivare le date dei fascicoli mancanti, e
inventare un recupero che a volte funziona sarebbe peggio di un avviso che si legge.
Il numero di fascicolo si azzera a ogni anno solare: un salto negativo a gennaio è
normale e non va segnalato.

## Errori

Stessa disciplina degli altri due fetcher: ogni serie è isolata, una che fallisce non
impedisce alle altre di aggiornarsi, l'errore finisce in `_meta.serie.<id>.errore` e
nel log. Uscita 0 se almeno una serie è andata a buon fine, 1 altrimenti. Un archivio
presente ma illeggibile è un guasto, non un archivio vuoto: si esce prima di
sovrascriverlo, come già fa `bandi_fetcher.php`.

## Test

Su fixture catturate dai feed reali, in `tests/fixtures/`:

- estrazione di numero e data del fascicolo dalla `<description>`
- separazione emittente/tipo atto, compreso il caso con due ` - `
- titolo senza separatore: emittente vuoto, non errore
- filtro sull'oggetto: un decreto il cui titolo è la sola data deve essere trovato
- **regressione dal campo**: i due decreti MASAF agricoli del numero 178 devono
  essere scartati
- classificazione della destinazione, compreso che `contribut` da solo non basta
- estrazione del codice atto, e scarto della voce quando manca ovunque
- salto di numero: da 176 a 178 segnala il 177 mancante; da 250 a 1 a gennaio no
- isolamento: una serie che fallisce non impedisce alle altre di aggiornarsi

## Fuori perimetro

Deliberatamente non incluso:

- **scraping dei BUR**: quindici parser fragili, decisione 1
- **recupero dei numeri saltati**: si segnala, non si ricostruisce
- **serie diverse dalla Generale**: configurazione, non codice, quando servirà
- **5ª Serie Contratti Pubblici**: pubblica appalti, che sono un tipo di bando
  diverso dai contributi alle imprese di pesca che la pagina raccoglie
