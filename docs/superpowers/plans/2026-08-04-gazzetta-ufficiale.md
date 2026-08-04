# Gazzetta Ufficiale come fonte istituzionale — piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** aggiungere la Gazzetta Ufficiale come quarta origine dei contenuti, smistando ogni atto in tema fra la pagina dei bandi e la coda di revisione del registro, e collegare i BUR regionali senza raschiarli.

**Architecture:** un terzo fetcher accanto a `news_fetcher.php` e `bandi_fetcher.php`, con la stessa divisione già adottata nel progetto: `gazzetta_fetcher.php` fa rete e orchestrazione, `lib/gazzetta_parser.php` e `lib/gazzetta_store.php` contengono funzioni pure verificabili su fixture. Le voci classificate come bandi confluiscono in `bandi.json` tramite le funzioni esistenti; quelle classificate come atti normativi vivono in `data/gazzetta.json` e appaiono nel riquadro "da rivedere" di `index.php`.

**Tech Stack:** PHP 8 senza dipendenze esterne, `simplexml_load_string` per l'RSS, `curl` di sistema per la rete, test fatti in casa con `t_eq`/`t_true`.

## Global Constraints

- **Niente openssl**: il modulo non è abilitato, `file_get_contents` non apre URL `https://`. Ogni richiesta di rete passa dall'eseguibile `curl` via `shell_exec`, come in `scraper.php:36-47` e `bandi_fetcher.php:39-51`.
- **Test**: si eseguono con `php tests/run.php` dalla radice. Ogni file `tests/test_*.php` viene incluso automaticamente da `glob`. Gli unici helper disponibili sono `t_eq($ottenuto, $atteso, $msg)` e `t_true($cond, $msg)`, definiti in `tests/run.php`. Non esiste PHPUnit: non usarlo.
- **Confronto stretto**: `t_eq` usa `===`. Un `int` non è uguale a una `string`.
- **Fuso orario**: ogni fetcher chiama `date_default_timezone_set('Europe/Rome')` prima di qualunque `date()`. Il server è su UTC.
- **Serializzazione JSON**: sempre `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.
- **Scrittura atomica**: si scrive su `$path . '.tmp'`, si verifica che i byte scritti coincidano con la lunghezza attesa, poi `rename()`. Modello in `lib/bandi_store.php:176-191`.
- **File assente ≠ file corrotto**: assente restituisce l'archivio vuoto, corrotto lancia `RuntimeException`. Modello in `lib/bandi_store.php:24-41`.
- **Isolamento per fonte**: una serie che fallisce non impedisce alle altre di aggiornarsi. Uscita 0 se almeno una serie è andata a buon fine, 1 altrimenti.
- **Lingua**: codice, commenti e messaggi di log in italiano, come tutto il repository. I commenti spiegano *perché*, non *cosa*.
- **Commit**: in italiano, con `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>` in coda.

---

## Struttura dei file

| file | responsabilità |
|---|---|
| `tests/fixtures/gazzetta_sg.xml` | sommario RSS di prova, deterministico: contiene atti reali catturati dal numero 178 più due costruiti per coprire i casi di smistamento |
| `lib/gazzetta_parser.php` | funzioni pure di lettura: sommario, scomposizione del titolo, codice atto, filtro in tema, destinazione |
| `lib/gazzetta_store.php` | funzioni pure di archivio: caricamento, salvataggio, merge, esito per fonte, numeri saltati |
| `data/gazzetta_fonti.json` | configurazione delle serie seguite |
| `data/gazzetta.json` | archivio delle voci in tema (creato al primo run, non versionato a mano) |
| `gazzetta_fetcher.php` | rete e orchestrazione |
| `index.php` | rende la coda di revisione della GU accanto a quella MASAF |
| `bandi.php`, `data/bandi_regioni.json` | link al BUR in testa a ogni sezione di regione |
| `tests/test_gazzetta_parser.php` | test delle funzioni di lettura |
| `tests/test_gazzetta_store.php` | test delle funzioni di archivio |
| `tests/test_pagine_markup.php` | esteso: coda GU in pagina, link BUR |

---

### Task 1: Lettura del sommario

**Files:**
- Create: `tests/fixtures/gazzetta_sg.xml`
- Create: `lib/gazzetta_parser.php`
- Test: `tests/test_gazzetta_parser.php`

**Interfaces:**
- Consumes: niente.
- Produces: `gazzetta_parse_sommario(string $xml): array` che restituisce
  `['numero' => int, 'data' => string 'YYYY-MM-DD', 'items' => list<array{titolo:string, oggetto:string, url:string}>]`.
  Lancia `RuntimeException` se l'XML non è un sommario leggibile.

- [ ] **Step 1: Creare la fixture**

Crea `tests/fixtures/gazzetta_sg.xml` con esattamente questo contenuto.

Cosa è reale e cosa no, perché conta per il valore dei test: **i sei titoli e l'intestazione del canale sono catturati dal fascicolo 178 del 03-08-2026**. Sono reali anche gli oggetti dei primi due atti — sono i due decreti MASAF agricoli su cui poggia il test di regressione del Task 3. Gli oggetti degli altri quattro sono costruiti: servono a coprire la scomposizione del titolo e i due esiti di smistamento, casi che il fascicolo 178 non conteneva.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<rss xmlns:content="http://purl.org/rss/1.0/modules/content/" version="2.0">
  <channel>
    <title>Gazzetta Ufficiale - Serie Generale - Sommario</title>
    <link>http://www.gazzettaufficiale.it/eli/gu/2026/08/03/178/SG/html</link>
    <description>Gazzetta Ufficiale - Serie Generale n. 178 del 03-08-2026</description>
    <language>it</language>
    <item>
      <title>MINISTERO DELL'AGRICOLTURA, DELLA SOVRANITA' ALIMENTARE E DELLE FORESTE - DECRETO 24 giugno 2026</title>
      <link>http://www.gazzettaufficiale.it/eli/id/2026/08/03/26A03853/SG</link>
      <content:encoded>Fondo Alimentare 2026 e 2027. Individuazione dei beneficiari del contributo economico previsto dall'articolo 1, commi 5 e 6 della legge 30 dicembre 2025 n. 199. Definizione dei termini e delle modalita' di erogazione delle risorse. (26A03853)</content:encoded>
      <pubDate>Sun, 02 Aug 2026 22:00:00 GMT</pubDate>
    </item>
    <item>
      <title>MINISTERO DELL'AGRICOLTURA, DELLA SOVRANITA' ALIMENTARE E DELLE FORESTE - DECRETO 23 luglio 2026</title>
      <link>http://www.gazzettaufficiale.it/eli/id/2026/08/03/26A03812/SG</link>
      <content:encoded>Dichiarazione dell'esistenza del carattere di eccezionalita' delle avversita' atmosferiche verificatesi nei territori della Regione Molise dal 30 marzo al 4 aprile 2026. (26A03812)</content:encoded>
      <pubDate>Sun, 02 Aug 2026 22:00:00 GMT</pubDate>
    </item>
    <item>
      <title>PRESIDENZA DEL CONSIGLIO DEI MINISTRI - DIPARTIMENTO PER LA TRASFORMAZIONE DIGITALE - DECRETO 19 marzo 2026</title>
      <link>http://www.gazzettaufficiale.it/eli/id/2026/08/03/26A03790/SG</link>
      <content:encoded>Disposizioni in materia di piattaforma digitale nazionale dati. (26A03790)</content:encoded>
      <pubDate>Sun, 02 Aug 2026 22:00:00 GMT</pubDate>
    </item>
    <item>
      <title>DECRETO LEGISLATIVO 26 giugno 2026, n.138</title>
      <link>http://www.gazzettaufficiale.it/eli/id/2026/08/03/26G00142/SG</link>
      <content:encoded>Disposizioni integrative e correttive in materia di contratti pubblici. (26G00142)</content:encoded>
      <pubDate>Sun, 02 Aug 2026 22:00:00 GMT</pubDate>
    </item>
    <item>
      <title>MINISTERO DELL'AGRICOLTURA, DELLA SOVRANITA' ALIMENTARE E DELLE FORESTE - DECRETO 15 luglio 2026</title>
      <link>http://www.gazzettaufficiale.it/eli/id/2026/08/03/26A03900/SG</link>
      <content:encoded>Disposizioni in materia di interruzione temporanea obbligatoria della pesca a strascico nelle GSA 17 e 18 per l'annualita' 2026. (26A03900)</content:encoded>
      <pubDate>Sun, 02 Aug 2026 22:00:00 GMT</pubDate>
    </item>
    <item>
      <title>MINISTERO DELL'AGRICOLTURA, DELLA SOVRANITA' ALIMENTARE E DELLE FORESTE - DECRETO 20 luglio 2026</title>
      <link>http://www.gazzettaufficiale.it/eli/id/2026/08/03/26A03901/SG</link>
      <content:encoded>Avviso pubblico per la presentazione delle domande di contributo alle imprese di acquacoltura. Termini e modalita' di partecipazione. (26A03901)</content:encoded>
      <pubDate>Sun, 02 Aug 2026 22:00:00 GMT</pubDate>
    </item>
  </channel>
</rss>
```

- [ ] **Step 2: Scrivere il test che fallisce**

Crea `tests/test_gazzetta_parser.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/gazzetta_parser.php';

$xml = (string) file_get_contents(__DIR__ . '/fixtures/gazzetta_sg.xml');

// --- lettura del sommario ---
$sommario = gazzetta_parse_sommario($xml);
t_eq($sommario['numero'], 178, 'gazzetta: numero del fascicolo non letto dalla description del canale');
t_eq($sommario['data'], '2026-08-03', 'gazzetta: data del fascicolo non convertita in ISO');
t_eq(count($sommario['items']), 6, 'gazzetta: non tutti gli atti del sommario sono stati letti');
t_eq(
    $sommario['items'][0]['titolo'],
    "MINISTERO DELL'AGRICOLTURA, DELLA SOVRANITA' ALIMENTARE E DELLE FORESTE - DECRETO 24 giugno 2026",
    'gazzetta: titolo del primo atto non letto'
);
t_true(
    str_starts_with($sommario['items'][0]['oggetto'], 'Fondo Alimentare 2026 e 2027.'),
    'gazzetta: oggetto non letto da content:encoded'
);
t_eq(
    $sommario['items'][0]['url'],
    'http://www.gazzettaufficiale.it/eli/id/2026/08/03/26A03853/SG',
    'gazzetta: URL ELI dell atto non letto'
);

// Un XML che non e' un sommario deve fallire in modo esplicito, non restituire
// un sommario vuoto: un feed cambiato in silenzio e' un guasto da vedere.
$rotto = false;
try {
    gazzetta_parse_sommario('<rss><channel><title>vuoto</title></channel></rss>');
} catch (RuntimeException) {
    $rotto = true;
}
t_true($rotto, 'gazzetta: un sommario senza item deve lanciare RuntimeException');
```

- [ ] **Step 3: Eseguire il test e verificare che fallisca**

Esegui: `php tests/run.php`
Atteso: errore fatale `Failed opening required .../lib/gazzetta_parser.php`.

- [ ] **Step 4: Scrivere l'implementazione minima**

Crea `lib/gazzetta_parser.php`:

```php
<?php
declare(strict_types=1);

/**
 * Lettura dei sommari RSS della Gazzetta Ufficiale.
 *
 * Ogni feed della GU non e' uno storico interrogabile ma il sommario di un solo
 * fascicolo: quello uscito per ultimo. Numero e data del fascicolo stanno nella
 * <description> del canale, non negli item, e servono a riconoscere i numeri
 * saltati fra un'esecuzione e l'altra.
 *
 * Qui dentro solo parsing e classificazione, niente rete: gazzetta_fetcher.php
 * fa le richieste, questo file resta verificabile su fixture.
 */

/**
 * @return array{numero:int, data:string, items:list<array{titolo:string, oggetto:string, url:string}>}
 */
function gazzetta_parse_sommario(string $xml): array
{
    // Stesso trim di news_parse_rss(): una dichiarazione XML non in colonna 0
    // rende il documento non ben formato e il caricamento fallisce.
    $xml = trim($xml);
    $prev = libxml_use_internal_errors(true);
    $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if ($sx === false || !isset($sx->channel->item)) {
        throw new RuntimeException('Sommario GU non valido o senza item');
    }

    $descrizione = trim((string) $sx->channel->description);
    if (preg_match('/n\.\s*(\d+)\s+del\s+(\d{2})-(\d{2})-(\d{4})/u', $descrizione, $m) !== 1) {
        throw new RuntimeException("Numero del fascicolo non riconosciuto: $descrizione");
    }

    $items = [];
    foreach ($sx->channel->item as $node) {
        $titolo = trim((string) preg_replace('/\s+/u', ' ', (string) $node->title));
        $url = trim((string) $node->link);
        if ($titolo === '' || $url === '') {
            continue;
        }
        // content:encoded sta in un namespace: ->children() con l'URI e' l'unico
        // modo per raggiungerlo da SimpleXML.
        $contenuto = $node->children('http://purl.org/rss/1.0/modules/content/');
        $oggetto = trim((string) preg_replace('/\s+/u', ' ', (string) $contenuto->encoded));
        $items[] = ['titolo' => $titolo, 'oggetto' => $oggetto, 'url' => $url];
    }

    return [
        'numero' => (int) $m[1],
        'data'   => sprintf('%s-%s-%s', $m[4], $m[3], $m[2]),
        'items'  => $items,
    ];
}
```

- [ ] **Step 5: Eseguire il test e verificare che passi**

Esegui: `php tests/run.php`
Atteso: nessun `FAIL` fra le righe nuove, conteggio finale `0 falliti`.

- [ ] **Step 6: Commit**

```bash
git add tests/fixtures/gazzetta_sg.xml lib/gazzetta_parser.php tests/test_gazzetta_parser.php
git commit -m "feat(gazzetta): lettura del sommario RSS della Gazzetta Ufficiale

Ogni feed della GU e' il sommario di un solo fascicolo, non uno storico: numero
e data stanno nella description del canale e servono a riconoscere i numeri
saltati. L'oggetto dell'atto sta in content:encoded, che e' in un namespace.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: Scomposizione del titolo e codice dell'atto

**Files:**
- Modify: `lib/gazzetta_parser.php` (in coda)
- Test: `tests/test_gazzetta_parser.php` (in coda)

**Interfaces:**
- Consumes: `gazzetta_parse_sommario()` dal Task 1.
- Produces:
  - `gazzetta_scompone_titolo(string $titolo): array{emittente:string, tipo_atto:string}`
  - `gazzetta_codice_atto(string $oggetto, string $url): string` — stringa vuota se il codice non è ricavabile da nessuna delle due fonti.

- [ ] **Step 1: Scrivere il test che fallisce**

Aggiungi in coda a `tests/test_gazzetta_parser.php`:

```php
// --- scomposizione del titolo ---
// Si separa sull'ULTIMO " - ": la Presidenza del Consiglio ne usa due, e
// spezzare sul primo attribuirebbe l'atto al dipartimento sbagliato.
$a = gazzetta_scompone_titolo("MINISTERO DELL'AGRICOLTURA, DELLA SOVRANITA' ALIMENTARE E DELLE FORESTE - DECRETO 24 giugno 2026");
t_eq($a['emittente'], "MINISTERO DELL'AGRICOLTURA, DELLA SOVRANITA' ALIMENTARE E DELLE FORESTE", 'gazzetta: emittente non estratto');
t_eq($a['tipo_atto'], 'DECRETO', 'gazzetta: tipo atto non estratto');

$b = gazzetta_scompone_titolo('PRESIDENZA DEL CONSIGLIO DEI MINISTRI - DIPARTIMENTO PER LA TRASFORMAZIONE DIGITALE - DECRETO 19 marzo 2026');
t_eq($b['emittente'], 'PRESIDENZA DEL CONSIGLIO DEI MINISTRI - DIPARTIMENTO PER LA TRASFORMAZIONE DIGITALE', 'gazzetta: con due separatori l emittente deve tenerli entrambi');
t_eq($b['tipo_atto'], 'DECRETO', 'gazzetta: tipo atto non estratto con due separatori');

// Senza separatore sono atti dello Stato: emittente vuoto, non errore.
$c = gazzetta_scompone_titolo('DECRETO LEGISLATIVO 26 giugno 2026, n.138');
t_eq($c['emittente'], '', 'gazzetta: senza separatore l emittente deve essere vuoto');
t_eq($c['tipo_atto'], 'DECRETO LEGISLATIVO', 'gazzetta: tipo atto su piu parole non estratto');

$d = gazzetta_scompone_titolo('AGENZIA ITALIANA DEL FARMACO - COMUNICATO');
t_eq($d['tipo_atto'], 'COMUNICATO', 'gazzetta: tipo atto senza data non estratto');

// --- codice dell'atto ---
t_eq(
    gazzetta_codice_atto('Fondo Alimentare 2026 e 2027. (26A03853)', 'http://www.gazzettaufficiale.it/eli/id/2026/08/03/26A03853/SG'),
    '26A03853',
    'gazzetta: codice atto non letto dalla coda dell oggetto'
);
// Quando l'oggetto non lo porta si ricade sull'URL ELI.
t_eq(
    gazzetta_codice_atto('Oggetto senza codice.', 'http://www.gazzettaufficiale.it/eli/id/2026/08/03/26A03812/SG'),
    '26A03812',
    'gazzetta: codice atto non recuperato dall URL ELI'
);
// Senza identificatore stabile la voce non e' registrabile: ogni run la
// ripresenterebbe come nuova. Stringa vuota, e chi chiama la scarta.
t_eq(
    gazzetta_codice_atto('Oggetto senza codice.', 'http://www.gazzettaufficiale.it/qualcosa/altro'),
    '',
    'gazzetta: senza codice ricavabile si deve restituire stringa vuota'
);
```

- [ ] **Step 2: Eseguire il test e verificare che fallisca**

Esegui: `php tests/run.php`
Atteso: `Call to undefined function gazzetta_scompone_titolo()`.

- [ ] **Step 3: Scrivere l'implementazione minima**

Aggiungi in coda a `lib/gazzetta_parser.php`:

```php
/**
 * Separa l'emittente dal tipo di atto.
 *
 * Il titolo GU ha la forma "<EMITTENTE> - <TIPO> <data>", ma l'emittente puo'
 * contenere a sua volta un " - " ("PRESIDENZA DEL CONSIGLIO DEI MINISTRI -
 * DIPARTIMENTO PER LA TRASFORMAZIONE DIGITALE - DECRETO 19 marzo 2026"): si
 * separa sull'ultimo, non sul primo, altrimenti l'atto verrebbe attribuito al
 * dipartimento anziche' alla presidenza.
 *
 * Quando il separatore manca (" DECRETO LEGISLATIVO 26 giugno 2026, n.138")
 * l'emittente e' vuoto: sono atti dello Stato, non di un ministero.
 *
 * @return array{emittente:string, tipo_atto:string}
 */
function gazzetta_scompone_titolo(string $titolo): array
{
    $titolo = trim($titolo);
    $pos = strrpos($titolo, ' - ');
    if ($pos === false) {
        $emittente = '';
        $coda = $titolo;
    } else {
        $emittente = trim(substr($titolo, 0, $pos));
        $coda = trim(substr($titolo, $pos + 3));
    }

    // Il tipo e' la sequenza di parole maiuscole in testa alla coda, fino alla
    // data o alla fine. Le lettere accentate maiuscole e l'apostrofo fanno
    // parte dei nomi degli atti, la virgola no.
    // Apici singoli e \x{...}: la classe di caratteri va consegnata a PCRE cosi'
    // com'e'. Fra apici doppi PHP interpreterebbe \u{...} da se', prima che il
    // motore delle espressioni regolari veda alcunche'.
    $tipo = $coda;
    if (preg_match('/^([A-Z\x{00C0}-\x{00DE}\'\s]+?)(?=\s+\d|,|$)/u', $coda, $m) === 1) {
        $tipo = trim($m[1]);
    }

    return ['emittente' => $emittente, 'tipo_atto' => $tipo];
}

/**
 * Identificatore stabile dell'atto, assegnato dalla fonte.
 *
 * Compare in coda all'oggetto fra parentesi e dentro l'URL ELI. Si preferisce
 * l'oggetto perche' e' la forma canonica; l'URL e' la riserva. Restituisce
 * stringa vuota quando non e' ricavabile da nessuna delle due: senza un
 * identificatore stabile ogni esecuzione ripresenterebbe la voce come nuova,
 * quindi chi chiama deve scartarla anziche' inventarne uno.
 */
function gazzetta_codice_atto(string $oggetto, string $url): string
{
    if (preg_match('/\(([0-9]{2}[A-Z][0-9]{5})\)\s*$/u', trim($oggetto), $m) === 1) {
        return $m[1];
    }
    if (preg_match('#/eli/id/\d{4}/\d{2}/\d{2}/([0-9]{2}[A-Z][0-9]{5})/#', $url, $m) === 1) {
        return $m[1];
    }
    return '';
}
```

- [ ] **Step 4: Eseguire il test e verificare che passi**

Esegui: `php tests/run.php`
Atteso: `0 falliti`.

- [ ] **Step 5: Commit**

```bash
git add lib/gazzetta_parser.php tests/test_gazzetta_parser.php
git commit -m "feat(gazzetta): emittente, tipo di atto e codice identificativo

Il titolo GU porta emittente e tipo di atto in forma strutturata, ma
l'emittente puo' contenere a sua volta un separatore: si spezza sull'ultimo,
non sul primo. Il codice dell'atto sta in coda all'oggetto e nell'URL ELI;
senza, la voce non e' registrabile e chi chiama la scarta.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: Filtro in tema e destinazione

**Files:**
- Modify: `lib/gazzetta_parser.php` (in coda)
- Test: `tests/test_gazzetta_parser.php` (in coda)

**Interfaces:**
- Consumes: `gazzetta_parse_sommario()`, `gazzetta_scompone_titolo()`, `gazzetta_codice_atto()`.
- Produces:
  - `gazzetta_in_tema(string $titolo, string $oggetto, array $keywords): bool`
  - `gazzetta_destinazione(string $oggetto): string` — `'bandi'` oppure `'registro'`
  - `gazzetta_voci(array $sommario, string $serieId, array $keywords, string $oggi): list<array>` — le voci in tema del sommario, già classificate e pronte per l'archivio.

- [ ] **Step 1: Scrivere il test che fallisce**

Aggiungi in coda a `tests/test_gazzetta_parser.php`:

```php
// --- filtro in tema ---
$kw = ['pesca', 'pescher', 'ittic', 'acquacolt', 'mollusch', 'vongol', 'tonno', 'FEAMPA', 'GSA', 'marittim'];

// Regressione dal campo: nel numero 178 il MASAF ha pubblicato due decreti,
// entrambi agricoli. Filtrare per solo emittente li farebbe entrare.
t_eq(
    gazzetta_in_tema(
        "MINISTERO DELL'AGRICOLTURA, DELLA SOVRANITA' ALIMENTARE E DELLE FORESTE - DECRETO 24 giugno 2026",
        'Fondo Alimentare 2026 e 2027. Individuazione dei beneficiari del contributo economico. (26A03853)',
        $kw
    ),
    false,
    'gazzetta: il decreto Fondo Alimentare non e in tema e non deve passare il filtro'
);
t_eq(
    gazzetta_in_tema(
        "MINISTERO DELL'AGRICOLTURA, DELLA SOVRANITA' ALIMENTARE E DELLE FORESTE - DECRETO 23 luglio 2026",
        "Dichiarazione dell'esistenza del carattere di eccezionalita' delle avversita' atmosferiche nella Regione Molise. (26A03812)",
        $kw
    ),
    false,
    'gazzetta: la declaratoria meteo non e in tema e non deve passare il filtro'
);
// Il titolo non porta mai la materia: se il filtro guardasse solo li', questo
// decreto sulla pesca non verrebbe mai trovato.
t_eq(
    gazzetta_in_tema(
        "MINISTERO DELL'AGRICOLTURA, DELLA SOVRANITA' ALIMENTARE E DELLE FORESTE - DECRETO 15 luglio 2026",
        "Disposizioni in materia di interruzione temporanea obbligatoria della pesca a strascico nelle GSA 17 e 18. (26A03900)",
        $kw
    ),
    true,
    'gazzetta: un decreto sulla pesca deve essere trovato dall oggetto anche se il titolo tace'
);

// --- destinazione ---
t_eq(
    gazzetta_destinazione("Avviso pubblico per la presentazione delle domande di contributo alle imprese di acquacoltura."),
    'bandi',
    'gazzetta: un avviso pubblico deve andare ai bandi'
);
t_eq(
    gazzetta_destinazione("Disposizioni in materia di interruzione temporanea obbligatoria della pesca a strascico."),
    'registro',
    'gazzetta: un decreto dispositivo deve andare al registro'
);
// "contributo" da solo e' un segnale troppo debole: nel numero 178 compare in
// un decreto che individua beneficiari, che e' un atto, non un avviso a cui ci
// si candida. Il dubbio va al registro, che ha un cancello umano.
t_eq(
    gazzetta_destinazione("Individuazione dei beneficiari del contributo economico previsto dalla legge."),
    'registro',
    'gazzetta: il solo contributo non basta a fare un bando'
);

// --- voci complete dal sommario ---
$voci = gazzetta_voci($sommario, 'gu-sg', $kw, '2026-08-04');
t_eq(count($voci), 2, 'gazzetta: dal sommario di prova devono uscire due sole voci in tema');
$perCodice = [];
foreach ($voci as $v) {
    $perCodice[$v['id']] = $v;
}
t_true(isset($perCodice['26A03900']), 'gazzetta: il decreto sul fermo non e fra le voci prodotte');
t_eq($perCodice['26A03900']['destinazione'], 'registro', 'gazzetta: il decreto sul fermo deve andare al registro');
t_eq($perCodice['26A03900']['tipo_atto'], 'DECRETO', 'gazzetta: tipo atto non riportato nella voce');
t_eq($perCodice['26A03900']['numero_gu'], 178, 'gazzetta: numero del fascicolo non riportato nella voce');
t_eq($perCodice['26A03900']['data_gu'], '2026-08-03', 'gazzetta: data del fascicolo non riportata nella voce');
t_eq($perCodice['26A03900']['serie'], 'gu-sg', 'gazzetta: id della serie non riportato nella voce');
t_eq($perCodice['26A03900']['status'], 'pending_review', 'gazzetta: una voce nuova deve nascere da rivedere');
t_eq($perCodice['26A03900']['first_seen'], '2026-08-04', 'gazzetta: data di primo avvistamento non riportata');
t_true(isset($perCodice['26A03901']), 'gazzetta: l avviso pubblico non e fra le voci prodotte');
t_eq($perCodice['26A03901']['destinazione'], 'bandi', 'gazzetta: l avviso pubblico deve andare ai bandi');
```

- [ ] **Step 2: Eseguire il test e verificare che fallisca**

Esegui: `php tests/run.php`
Atteso: `Call to undefined function gazzetta_in_tema()`.

- [ ] **Step 3: Scrivere l'implementazione minima**

Aggiungi in coda a `lib/gazzetta_parser.php`:

```php
/**
 * Un atto e' in tema se le parole chiave compaiono nell'oggetto o nel titolo.
 *
 * L'oggetto e' la parte che conta: nella GU il titolo dice chi ha firmato e che
 * tipo di atto e', non di cosa tratta ("MINISTERO DELL'AGRICOLTURA... - DECRETO
 * 15 luglio 2026"). Un filtro sul solo titolo, come quello usato per i feed
 * regionali, sulla GU non troverebbe nulla. Il titolo si guarda comunque perche'
 * altre serie ci mettono la materia.
 *
 * Filtrare per solo emittente non e' un'alternativa: il MASAF governa
 * agricoltura, foreste e pesca, e la pesca e' la minoranza dei suoi atti.
 */
function gazzetta_in_tema(string $titolo, string $oggetto, array $keywords): bool
{
    if ($keywords === []) {
        return true;
    }
    $pattern = '/' . implode('|', array_map('preg_quote', $keywords)) . '/iu';
    return preg_match($pattern, $oggetto . ' ' . $titolo) === 1;
}

/**
 * Dove va a finire l'atto: pagina dei bandi o coda di revisione del registro.
 *
 * Il dubbio va al registro, non ai bandi: il registro ha un cancello umano - le
 * voci restano "da rivedere" finche' qualcuno non le guarda - mentre la pagina
 * dei bandi pubblica quello che riceve. Un decreto finito per errore fra i
 * bandi viene visto dai lettori; un bando finito per errore nella coda di
 * revisione viene visto dal curatore, che lo sposta.
 *
 * "contribut" non e' fra i segnali: e' frequente negli atti che individuano
 * beneficiari, che sono provvedimenti e non avvisi a cui ci si candida.
 */
function gazzetta_destinazione(string $oggetto): string
{
    $segnali = [
        'bando',
        'avviso pubblico',
        'graduatoria',
        'manifestazione di interesse',
        'domande di partecipazione',
    ];
    $pattern = '/' . implode('|', array_map('preg_quote', $segnali)) . '/iu';
    return preg_match($pattern, $oggetto) === 1 ? 'bandi' : 'registro';
}

/**
 * Le voci in tema di un sommario, gia' classificate e pronte per l'archivio.
 *
 * Gli atti senza codice identificativo vengono scartati: senza un id stabile
 * ogni esecuzione li ripresenterebbe come nuovi e la coda di revisione non si
 * svuoterebbe mai.
 *
 * @param array{numero:int, data:string, items:list<array{titolo:string, oggetto:string, url:string}>} $sommario
 * @return list<array>
 */
function gazzetta_voci(array $sommario, string $serieId, array $keywords, string $oggi): array
{
    $voci = [];
    foreach ($sommario['items'] as $item) {
        if (!gazzetta_in_tema($item['titolo'], $item['oggetto'], $keywords)) {
            continue;
        }
        $codice = gazzetta_codice_atto($item['oggetto'], $item['url']);
        if ($codice === '') {
            continue;
        }
        $parti = gazzetta_scompone_titolo($item['titolo']);
        $voci[$codice] = [
            'id'           => $codice,
            'serie'        => $serieId,
            'emittente'    => $parti['emittente'],
            'tipo_atto'    => $parti['tipo_atto'],
            'titolo'       => $item['titolo'],
            'oggetto'      => $item['oggetto'],
            'url'          => $item['url'],
            'numero_gu'    => $sommario['numero'],
            'data_gu'      => $sommario['data'],
            'destinazione' => gazzetta_destinazione($item['oggetto']),
            'status'       => 'pending_review',
            'first_seen'   => $oggi,
        ];
    }
    return array_values($voci);
}
```

- [ ] **Step 4: Eseguire il test e verificare che passi**

Esegui: `php tests/run.php`
Atteso: `0 falliti`.

- [ ] **Step 5: Commit**

```bash
git add lib/gazzetta_parser.php tests/test_gazzetta_parser.php
git commit -m "feat(gazzetta): filtro in tema sull'oggetto e smistamento per natura dell'atto

Nella GU il titolo dice chi firma e che tipo di atto e', non di cosa tratta: il
filtro deve guardare l'oggetto, altrimenti non trova nulla. Filtrare per solo
emittente non basta - il MASAF governa anche agricoltura e foreste, e i due
decreti che ha pubblicato nel numero 178 sono entrambi agricoli.

Il dubbio sullo smistamento va al registro, che ha un cancello umano, non ai
bandi, che pubblicano quello che ricevono.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: Archivio e numeri saltati

**Files:**
- Create: `lib/gazzetta_store.php`
- Test: `tests/test_gazzetta_store.php`

**Interfaces:**
- Consumes: le voci prodotte da `gazzetta_voci()` (Task 3).
- Produces:
  - `gazzetta_store_empty(): array`
  - `gazzetta_store_load(string $path): array`
  - `gazzetta_store_save(string $path, array $store): void`
  - `gazzetta_store_merge(array $store, string $serieId, array $voci, int $numero, string $data, string $nowIso): array`
  - `gazzetta_store_mark_failure(array $store, string $serieId, string $errore, string $nowIso): array`
  - `gazzetta_numeri_saltati(?int $ultimo, int $corrente): list<int>`

- [ ] **Step 1: Scrivere il test che fallisce**

Crea `tests/test_gazzetta_store.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/gazzetta_store.php';

// --- numeri saltati ---
t_eq(gazzetta_numeri_saltati(176, 178), [177], 'gazzetta: il numero saltato non viene riconosciuto');
t_eq(gazzetta_numeri_saltati(177, 178), [], 'gazzetta: due numeri consecutivi non hanno salti');
t_eq(gazzetta_numeri_saltati(178, 178), [], 'gazzetta: rileggere lo stesso numero non e un salto');
t_eq(gazzetta_numeri_saltati(null, 178), [], 'gazzetta: alla prima esecuzione non si puo parlare di salti');
// Il numero riparte da 1 ogni anno solare: un salto all'indietro a gennaio e'
// normale e segnalarlo insegnerebbe a ignorare le segnalazioni.
t_eq(gazzetta_numeri_saltati(250, 1), [], 'gazzetta: il cambio d anno non e un salto');
t_eq(gazzetta_numeri_saltati(170, 175), [171, 172, 173, 174], 'gazzetta: salto multiplo non elencato per intero');

// --- archivio vuoto e merge ---
$vuoto = gazzetta_store_empty();
t_eq($vuoto['items'], [], 'gazzetta: archivio vuoto deve avere items vuoto');

$voce = [
    'id' => '26A03900', 'serie' => 'gu-sg', 'emittente' => 'MINISTERO', 'tipo_atto' => 'DECRETO',
    'titolo' => 'MINISTERO - DECRETO 15 luglio 2026', 'oggetto' => 'Interruzione della pesca. (26A03900)',
    'url' => 'http://www.gazzettaufficiale.it/eli/id/2026/08/03/26A03900/SG',
    'numero_gu' => 178, 'data_gu' => '2026-08-03', 'destinazione' => 'registro',
    'status' => 'pending_review', 'first_seen' => '2026-08-04',
];
$s = gazzetta_store_merge($vuoto, 'gu-sg', [$voce], 178, '2026-08-03', '2026-08-04T15:00:00+02:00');
t_eq(count($s['items']), 1, 'gazzetta: la voce non e stata inserita');
t_eq($s['_meta']['serie']['gu-sg']['ultimo_numero'], 178, 'gazzetta: ultimo numero non registrato');
t_eq($s['_meta']['serie']['gu-sg']['errore'], null, 'gazzetta: un merge riuscito non deve lasciare errori');

// Lo stato assegnato a mano dal curatore non deve essere sovrascritto da un
// secondo passaggio della stessa voce: e' l'unica informazione che la fonte
// non possiede.
$s['items']['26A03900']['status'] = 'curated';
$s2 = gazzetta_store_merge($s, 'gu-sg', [$voce], 179, '2026-08-04', '2026-08-05T15:00:00+02:00');
t_eq($s2['items']['26A03900']['status'], 'curated', 'gazzetta: il merge ha sovrascritto uno stato deciso a mano');
t_eq(count($s2['items']), 1, 'gazzetta: la stessa voce e stata duplicata');

// --- fallimento di una serie ---
$sf = gazzetta_store_mark_failure($s2, 'gu-sg', 'download fallito', '2026-08-05T15:00:00+02:00');
t_eq($sf['_meta']['serie']['gu-sg']['errore'], 'download fallito', 'gazzetta: errore non registrato');
t_eq($sf['_meta']['serie']['gu-sg']['ultimo_numero'], 179, 'gazzetta: un fallimento non deve perdere l ultimo numero visto');

// --- caricamento ---
$tmp = sys_get_temp_dir() . '/gazzetta_test_' . getmypid() . '.json';
@unlink($tmp);
t_eq(gazzetta_store_load($tmp)['items'], [], 'gazzetta: un file assente deve dare archivio vuoto');
gazzetta_store_save($tmp, $s2);
t_eq(count(gazzetta_store_load($tmp)['items']), 1, 'gazzetta: la voce non sopravvive al salvataggio');
// File presente ma corrotto e' un guasto, non un archivio vuoto: degradare qui
// farebbe riscrivere l'archivio buono con il nulla al run successivo.
file_put_contents($tmp, '{"items": tronc');
$corrotto = false;
try {
    gazzetta_store_load($tmp);
} catch (RuntimeException) {
    $corrotto = true;
}
t_true($corrotto, 'gazzetta: un archivio corrotto deve lanciare RuntimeException');
@unlink($tmp);
```

- [ ] **Step 2: Eseguire il test e verificare che fallisca**

Esegui: `php tests/run.php`
Atteso: errore fatale `Failed opening required .../lib/gazzetta_store.php`.

- [ ] **Step 3: Scrivere l'implementazione minima**

Crea `lib/gazzetta_store.php`:

```php
<?php
declare(strict_types=1);

/**
 * Archivio delle voci lette dalla Gazzetta Ufficiale.
 *
 * Indicizzato per codice dell'atto, non per posizione: le voci vanno ritrovate
 * per aggiornarle senza duplicarle, e il codice e' l'unico identificatore
 * stabile che la fonte assegna.
 *
 * Non e' data/known.json: quel file e' indicizzato per IDPagina MASAF ed e' di
 * proprieta' di scraper.php. Infilarci voci con un identificatore di forma
 * diversa romperebbe il contratto fra i due programmi.
 */

function gazzetta_store_empty(): array
{
    return ['_meta' => ['last_run' => null, 'serie' => []], 'items' => []];
}

/**
 * File assente e file corrotto sono fatti diversi: il primo e' lo stato
 * iniziale legittimo, il secondo un guasto. Se qui il corrotto degradasse a
 * "vuoto", il salvataggio finale riscriverebbe l'archivio perdendo tutto.
 */
function gazzetta_store_load(string $path): array
{
    if (!file_exists($path)) {
        return gazzetta_store_empty();
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Archivio illeggibile: $path");
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
        throw new RuntimeException("Archivio corrotto o non decodificabile: $path (" . json_last_error_msg() . ')');
    }
    $data['_meta'] ??= ['last_run' => null, 'serie' => []];
    $data['_meta']['serie'] ??= [];
    return $data;
}

/** Scrittura atomica, come lib/bandi_store.php: una scrittura parziale non deve sostituire l'archivio buono. */
function gazzetta_store_save(string $path, array $store): void
{
    $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('json_encode fallito: ' . json_last_error_msg());
    }
    $tmp = $path . '.tmp';
    $scritti = file_put_contents($tmp, $json);
    if ($scritti === false || $scritti !== strlen($json)) {
        @unlink($tmp);
        throw new RuntimeException("Scrittura incompleta: $path");
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("Sostituzione del file fallita: $path");
    }
}

/**
 * Unisce le voci di una serie a quelle gia' presenti.
 *
 * Lo 'status' non si aggiorna mai da fonte: e' l'unica informazione che la GU
 * non possiede, la decide il curatore. Sovrascriverlo rimetterebbe "da
 * rivedere" su ogni voce gia' valutata, a ogni esecuzione.
 */
function gazzetta_store_merge(array $store, string $serieId, array $voci, int $numero, string $data, string $nowIso): array
{
    foreach ($voci as $voce) {
        $id = (string) $voce['id'];
        if (isset($store['items'][$id])) {
            $voce['status']     = $store['items'][$id]['status'];
            $voce['first_seen'] = $store['items'][$id]['first_seen'];
        }
        $store['items'][$id] = $voce;
    }
    $precedente = $store['_meta']['serie'][$serieId] ?? [];
    $store['_meta']['serie'][$serieId] = [
        'ultimo_numero' => $numero,
        'ultima_data'   => $data,
        'last_ok'       => $nowIso,
        'errore'        => null,
        'saltati'       => $precedente['saltati'] ?? [],
    ];
    return $store;
}

/** Registra il guasto senza perdere l'ultimo numero visto: al prossimo giro serve per contare i salti. */
function gazzetta_store_mark_failure(array $store, string $serieId, string $errore, string $nowIso): array
{
    $precedente = $store['_meta']['serie'][$serieId] ?? [
        'ultimo_numero' => null, 'ultima_data' => null, 'last_ok' => null, 'saltati' => [],
    ];
    $store['_meta']['serie'][$serieId] = [
        'ultimo_numero' => $precedente['ultimo_numero'] ?? null,
        'ultima_data'   => $precedente['ultima_data'] ?? null,
        'last_ok'       => $precedente['last_ok'] ?? null,
        'errore'        => $errore,
        'saltati'       => $precedente['saltati'] ?? [],
    ];
    return $store;
}

/**
 * I numeri di fascicolo non visti fra l'ultima esecuzione e questa.
 *
 * Il feed e' il sommario di un solo numero: se il job salta due giorni, quei
 * fascicoli sono persi e non c'e' modo di derivarne le date dal feed. Si
 * segnalano invece di ricostruirli: un recupero che funziona a volte e' peggio
 * di un avviso che si legge.
 *
 * Il numero riparte da 1 a ogni anno solare, quindi un salto all'indietro non
 * e' un buco ma un capodanno.
 *
 * @return list<int>
 */
function gazzetta_numeri_saltati(?int $ultimo, int $corrente): array
{
    // La soglia e' 2, non 0: con numeri consecutivi (177 -> 178) l'intervallo
    // dei mancanti sarebbe range(178, 177), che in PHP non e' vuoto ma
    // discendente, e segnalerebbe come saltati due fascicoli entrambi visti.
    if ($ultimo === null || $corrente - $ultimo < 2) {
        return [];
    }
    return range($ultimo + 1, $corrente - 1);
}
```

- [ ] **Step 4: Eseguire il test e verificare che passi**

Esegui: `php tests/run.php`
Atteso: `0 falliti`.

- [ ] **Step 5: Commit**

```bash
git add lib/gazzetta_store.php tests/test_gazzetta_store.php
git commit -m "feat(gazzetta): archivio per codice atto e conteggio dei numeri saltati

Lo status non si aggiorna mai da fonte: e' l'unica informazione che la GU non
possiede, la decide il curatore, e sovrascriverlo rimetterebbe 'da rivedere' su
ogni voce gia' valutata a ogni esecuzione.

Il feed e' il sommario di un solo fascicolo: i numeri saltati si segnalano e non
si ricostruiscono, perche' dal feed non se ne possono derivare le date. Il
numero riparte da 1 ogni anno, quindi un salto all'indietro e' un capodanno.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: Il fetcher

**Files:**
- Create: `data/gazzetta_fonti.json`
- Create: `gazzetta_fetcher.php`
- Modify: `.gitignore` (aggiunge `data/gazzetta.json.tmp`)

**Interfaces:**
- Consumes: tutto `lib/gazzetta_parser.php` e `lib/gazzetta_store.php`.
- Produces: `data/gazzetta.json` popolato; nessuna funzione riusata altrove.

- [ ] **Step 1: Creare la configurazione**

Crea `data/gazzetta_fonti.json`:

```json
{
  "_commento": "Serie della Gazzetta Ufficiale seguite. Attenzione ai codici: SG e' la Serie Generale, S1 e' la 1a Serie Speciale (Corte Costituzionale), non la generale. Le altre serie si aggiungono qui senza toccare il codice: S2 Unione Europea, S3 Regioni, S4 Concorsi, S5 Contratti Pubblici.",
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

- [ ] **Step 2: Scrivere il fetcher**

Crea `gazzetta_fetcher.php`:

```php
<?php
declare(strict_types=1);

/**
 * Scarica i sommari delle serie della Gazzetta Ufficiale, tiene le voci in tema
 * e le smista fra la coda di revisione del registro e la pagina dei bandi.
 *
 * Ogni serie e' isolata: una che fallisce non impedisce alle altre di
 * aggiornarsi. L'archivio non viene mai potato.
 *
 * Uso: php gazzetta_fetcher.php
 */

require_once __DIR__ . '/lib/gazzetta_parser.php';
require_once __DIR__ . '/lib/gazzetta_store.php';
require_once __DIR__ . '/lib/news_normalize.php';
require_once __DIR__ . '/lib/bandi_parser.php';
require_once __DIR__ . '/lib/bandi_store.php';

// date.timezone e' UTC sul server: senza questo ogni timestamp sarebbe sfasato
// di due ore rispetto all'ora italiana.
date_default_timezone_set('Europe/Rome');

$dataDir    = __DIR__ . '/data';
$storeFile  = $dataDir . '/gazzetta.json';
$bandiFile  = $dataDir . '/bandi.json';
$fontiFile  = $dataDir . '/gazzetta_fonti.json';
$logFile    = $dataDir . '/gazzetta.log';

function gz_log(string $msg, string $logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

/** Come negli altri fetcher: senza openssl i wrapper https:// non esistono. */
function gz_fetch(string $url): string {
    $cmd = sprintf(
        'curl -s -L -A %s --max-time 25 %s',
        escapeshellarg('Mozilla/5.0 (Windows NT 10.0; Win64; x64) masaf-decreti-pesca-gazzetta/1.0'),
        escapeshellarg($url)
    );
    $body = shell_exec($cmd);
    if ($body === null || trim($body) === '') {
        throw new RuntimeException("download fallito (curl): $url");
    }
    return $body;
}

$fonti = json_decode((string) @file_get_contents($fontiFile), true);
if (!is_array($fonti) || !isset($fonti['serie']) || !is_array($fonti['serie'])) {
    gz_log('ERRORE: data/gazzetta_fonti.json mancante o non valido', $logFile);
    exit(1);
}

$now  = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('c');
$oggi = date('Y-m-d');

try {
    $store = gazzetta_store_load($storeFile);
} catch (Throwable $e) {
    // Archivio presente ma illeggibile: e' un guasto, non un archivio vuoto.
    // Si esce prima di toccarlo, cosi' un run successivo con il file riparato
    // non lo trova gia' svuotato.
    gz_log('ERRORE caricamento archivio: ' . $e->getMessage(), $logFile);
    exit(1);
}

$ok = 0;
$ko = 0;
$perBandi = [];

foreach ($fonti['serie'] as $serie) {
    $id = (string) $serie['id'];
    try {
        $sommario = gazzetta_parse_sommario(gz_fetch((string) $serie['url']));

        $ultimo = $store['_meta']['serie'][$id]['ultimo_numero'] ?? null;
        $saltati = gazzetta_numeri_saltati($ultimo, $sommario['numero']);
        if ($saltati !== []) {
            gz_log(
                "$id: ATTENZIONE, non visti i fascicoli " . implode(', ', $saltati)
                . ' (dal ' . $ultimo . ' al ' . $sommario['numero'] . ')',
                $logFile
            );
        }

        $voci = gazzetta_voci($sommario, $id, $serie['keywords'] ?? [], $oggi);
        $store = gazzetta_store_merge($store, $id, $voci, $sommario['numero'], $sommario['data'], $now);
        if ($saltati !== []) {
            $store['_meta']['serie'][$id]['saltati'] = array_values(array_unique(array_merge(
                $store['_meta']['serie'][$id]['saltati'] ?? [],
                $saltati
            )));
        }

        foreach ($voci as $voce) {
            if ($voce['destinazione'] === 'bandi') {
                $perBandi[] = $voce;
            }
        }

        gz_log(
            "$id: fascicolo {$sommario['numero']} del {$sommario['data']}, "
            . count($sommario['items']) . ' atti letti, ' . count($voci) . ' in tema',
            $logFile
        );
        $ok++;
    } catch (Throwable $e) {
        $store = gazzetta_store_mark_failure($store, $id, $e->getMessage(), $now);
        gz_log("$id: ERRORE " . $e->getMessage(), $logFile);
        $ko++;
    }
}

$store['_meta']['last_run'] = $now;

try {
    gazzetta_store_save($storeFile, $store);
} catch (Throwable $e) {
    gz_log('ERRORE salvataggio: ' . $e->getMessage(), $logFile);
    exit(1);
}

// --- travaso verso la pagina dei bandi ---
// Le voci classificate come bandi entrano nell'archivio dei bandi con la stessa
// forma delle segnalazioni dai feed: senza scadenza e marcate come incomplete,
// perche' la GU pubblica l'atto, non il termine di partecipazione.
if ($perBandi !== []) {
    try {
        $bandi = bandi_store_load($bandiFile);
        $vociBandi = [];
        foreach ($perBandi as $voce) {
            $vociBandi[] = bandi_voce([
                'id'                => news_item_id($voce['url']),
                'origine'           => 'gazzetta',
                'regioni'           => [],
                'titolo'            => $voce['oggetto'],
                'scopo'             => $voce['oggetto'],
                'pubblicazione'     => $voce['data_gu'],
                'scadenza'          => null,
                'nota'              => 'Pubblicato in Gazzetta Ufficiale n. ' . $voce['numero_gu'] . ' del ' . $voce['data_gu'],
                'url_fonte'         => $voce['url'],
                'url_ufficiale'     => $voce['url'],
                'dettagli_mancanti' => true,
                'fonte_label'       => 'Gazzetta Ufficiale',
            ]);
        }
        $bandi = bandi_store_merge($bandi, 'gu-sg', $vociBandi, $now);
        bandi_store_save($bandiFile, $bandi);
        gz_log(count($vociBandi) . ' voci travasate nella pagina dei bandi', $logFile);
    } catch (Throwable $e) {
        // Il travaso fallito non annulla la raccolta: l'archivio GU e' gia'
        // salvato e le voci restano, il prossimo run ritenta.
        gz_log('ERRORE travaso bandi: ' . $e->getMessage(), $logFile);
    }
}

$daRivedere = count(array_filter(
    $store['items'],
    static fn(array $v): bool => $v['status'] === 'pending_review' && $v['destinazione'] === 'registro'
));
gz_log("Serie ok: $ok, fallite: $ko, voci in archivio: " . count($store['items']) . ", da rivedere: $daRivedere", $logFile);
exit($ok > 0 ? 0 : 1);
```

- [ ] **Step 3: Ignorare il file temporaneo**

Aggiungi in coda a `.gitignore`:

```
data/gazzetta.json.tmp
```

- [ ] **Step 4: Eseguire il fetcher sul feed reale**

Esegui: `php gazzetta_fetcher.php`

Atteso: una riga `gu-sg: fascicolo <N> del <data>, <n> atti letti, <m> in tema` e una riga di riepilogo. `$m` sarà spesso 0: sono attesi pochi atti sulla pesca al mese, non a ogni fascicolo. Verifica che `data/gazzetta.json` sia stato creato e sia JSON valido:

```bash
php -r "var_dump(json_decode(file_get_contents('data/gazzetta.json'), true) !== null);"
```

Atteso: `bool(true)`.

- [ ] **Step 5: Verificare che i test non siano regrediti**

Esegui: `php tests/run.php`
Atteso: `0 falliti`.

- [ ] **Step 6: Commit**

```bash
git add data/gazzetta_fonti.json gazzetta_fetcher.php .gitignore data/gazzetta.json
git commit -m "feat(gazzetta): fetcher dei sommari e travaso verso la pagina dei bandi

Terza raccolta accanto a news e bandi, con lo stesso isolamento per fonte: una
serie che fallisce non ferma le altre. Le voci classificate come bandi entrano
nell'archivio dei bandi con la forma delle segnalazioni dai feed - senza
scadenza e marcate incomplete - perche' la GU pubblica l'atto, non il termine.

Un travaso fallito non annulla la raccolta: l'archivio GU e' gia' salvato e il
prossimo run ritenta.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 6: La coda GU nel riquadro "da rivedere"

**Files:**
- Modify: `index.php:8` (caricamento dei dati), `index.php:12-13` (costruzione della coda), `index.php:119` (contatore in intestazione), `index.php:141-145` (riquadro ed elenco)
- Test: `tests/test_pagine_markup.php` (in coda)

**Interfaces:**
- Consumes: `data/gazzetta.json` prodotto dal Task 5.
- Produces: markup con `li[data-origine="gazzetta"]` dentro `.pending-box`.

- [ ] **Step 1: Scrivere il test che fallisce**

Aggiungi in coda a `tests/test_pagine_markup.php`:

```php
// --- coda di revisione della Gazzetta Ufficiale ---
// Le voci GU vivono in un archivio separato da known.json ma appaiono nello
// stesso riquadro: chi cura ha una coda sola da guardare, non due.
$gazzetta = json_decode((string) @file_get_contents(__DIR__ . '/../data/gazzetta.json'), true);
$attesiGu = 0;
foreach ($gazzetta['items'] ?? [] as $v) {
    if (($v['status'] ?? '') === 'pending_review' && ($v['destinazione'] ?? '') === 'registro') {
        $attesiGu++;
    }
}
$resiGu = pm_count($x, "//li[@data-origine='gazzetta']");
t_eq($resiGu, $attesiGu, 'index.php: le voci GU da rivedere in pagina non coincidono con quelle in archivio');
```

- [ ] **Step 2: Eseguire il test e verificare che fallisca**

Esegui: `php tests/run.php`

Atteso: se `data/gazzetta.json` contiene almeno una voce da rivedere, `FAIL` con `atteso: <n>, ottenuto: 0`. Se l'archivio non ne contiene ancora (probabile: la pesca compare di rado in GU), il test passa già con `0 === 0`. In quel caso, per vedere il test fallire davvero, aggiungi temporaneamente a mano una voce di prova in `data/gazzetta.json` con `"status": "pending_review"` e `"destinazione": "registro"`, verifica il `FAIL`, e rimuovila dopo lo Step 4.

- [ ] **Step 3: Scrivere l'implementazione**

In `index.php`, dopo la riga che carica `$known` (riga 8-9), aggiungi:

```php
$gazzetta = json_decode((string) @file_get_contents($dataDir . '/gazzetta.json'), true) ?? ['items' => []];
```

Subito dopo la costruzione di `$pending` (la `array_filter` su `$known['items']`), aggiungi:

```php
// Gli atti in tema letti dalla Gazzetta Ufficiale hanno un archivio proprio -
// known.json e' indicizzato per IDPagina MASAF e appartiene a scraper.php - ma
// finiscono nello stesso riquadro: chi cura deve avere una coda sola da
// guardare, non due in pagine diverse.
$pendingGu = array_filter(
    $gazzetta['items'] ?? [],
    static fn(array $v): bool => ($v['status'] ?? '') === 'pending_review'
        && ($v['destinazione'] ?? '') === 'registro'
);
usort($pendingGu, fn($a, $b) => strcmp($b['data_gu'] ?? '', $a['data_gu'] ?? ''));
$totalePending = count($pending) + count($pendingGu);
```

Nell'intestazione, sostituisci la riga del contatore:

```php
      <span>Da rivedere: <strong><?= count($pending) ?></strong></span>
```

con:

```php
      <span>Da rivedere: <strong><?= $totalePending ?></strong></span>
```

Sostituisci la condizione di apertura del riquadro:

```php
  <?php if (count($pending) > 0): ?>
  <div class="pending-box">
    <h2>⚠ <?= count($pending) ?> nuovi atti da rivedere</h2>
```

con:

```php
  <?php if ($totalePending > 0): ?>
  <div class="pending-box">
    <h2>⚠ <?= $totalePending ?> nuovi atti da rivedere</h2>
```

E subito prima del `</ul>` che chiude l'elenco delle voci MASAF, aggiungi il secondo ciclo:

```php
      <?php foreach ($pendingGu as $g): ?>
      <li data-origine="gazzetta" data-search="<?= h(trim($g['titolo'] . ' ' . $g['oggetto'] . ' da rivedere gazzetta ufficiale')) ?>">
        <a href="<?= h($g['url']) ?>" target="_blank" rel="noopener"><?= h($g['oggetto']) ?></a>
        <div class="tag">Gazzetta Ufficiale n. <?= (int) $g['numero_gu'] ?> del <?= h($g['data_gu']) ?> · <?= h($g['tipo_atto']) ?> · non ancora verificato/categorizzato</div>
      </li>
      <?php endforeach; ?>
```

- [ ] **Step 4: Eseguire il test e verificare che passi**

Esegui: `php tests/run.php`
Atteso: `0 falliti`.

Verifica anche che la pagina non emetta avvisi:

```bash
php -d error_reporting=E_ALL -d display_errors=1 index.php > /dev/null
```

Atteso: nessun output su stderr.

- [ ] **Step 5: Commit**

```bash
git add index.php tests/test_pagine_markup.php
git commit -m "feat(gazzetta): gli atti letti in GU entrano nel riquadro da rivedere

L'archivio e' separato - known.json e' indicizzato per IDPagina MASAF e
appartiene a scraper.php - ma la coda che si guarda deve essere una sola: due
elenchi in due posti diversi sono due elenchi che nessuno svuota.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 7: Link ai Bollettini regionali

**Files:**
- Modify: `data/bandi_regioni.json` (campo `bur` per le regioni costiere)
- Modify: `bandi.php:290-300` (intestazione di sezione)
- Test: `tests/test_pagine_markup.php` (in coda)

**Interfaces:**
- Consumes: niente dai task precedenti.
- Produces: markup `a.bur` dentro l'intestazione di ogni sezione di regione che ha il campo.

- [ ] **Step 1: Scrivere il test che fallisce**

Aggiungi in coda a `tests/test_pagine_markup.php`:

```php
// --- link ai Bollettini Ufficiali regionali ---
// I BUR non hanno feed leggibili (il Veneto e' un'applicazione ASPX, la Sicilia
// risponde 404): restano collegati in testa alla sezione, come i FLAG senza
// feed. Assente non e' vuoto: le regioni senza BUR configurato non rendono nulla.
$regioniCfg = json_decode((string) file_get_contents(__DIR__ . '/../data/bandi_regioni.json'), true);
$conBur = 0;
foreach ($regioniCfg['regioni'] ?? [] as $r) {
    if (trim((string) ($r['bur'] ?? '')) !== '') {
        $conBur++;
    }
}
t_true($conBur > 0, 'bandi_regioni.json: nessuna regione ha il BUR configurato, il controllo non verifica nulla');
t_eq(pm_count($xb, "//a[contains(@class,'bur')]"), $conBur, 'bandi.php: i link ai BUR in pagina non coincidono con quelli configurati');
```

- [ ] **Step 2: Eseguire il test e verificare che fallisca**

Esegui: `php tests/run.php`
Atteso: `FAIL: bandi_regioni.json: nessuna regione ha il BUR configurato`.

- [ ] **Step 3: Aggiungere i BUR alla configurazione**

In `data/bandi_regioni.json`, aggiungi il campo `bur` a ciascuna delle regioni costiere, subito dopo `calendario_ufficiale`. Le regioni senza sbocco al mare (Lombardia, Piemonte, Umbria, Valle d'Aosta, Trentino-Alto Adige) non lo ricevono: non hanno pesca marittima e un link inutile è rumore.

```
abruzzo         https://bura.regione.abruzzo.it/
basilicata      https://www.regione.basilicata.it/giunta/site/giunta/department.jsp?dep=100049
calabria        https://portale.regione.calabria.it/website/burc/
campania        https://burc.regione.campania.it/
emilia-romagna  https://bur.regione.emilia-romagna.it/
friuli-venezia-giulia  https://www.regione.fvg.it/rafvg/cms/RAFVG/GEN/bollettino-ufficiale/
lazio           https://www.regione.lazio.it/burl
liguria         https://www.regione.liguria.it/homepage-bollettino-ufficiale.html
marche          https://www.regione.marche.it/Regione-Utile/Bollettino-Ufficiale
molise          https://bollettino.regione.molise.it/
puglia          https://burp.regione.puglia.it/
sardegna        https://buras.regione.sardegna.it/
sicilia         https://www.gurs.regione.sicilia.it/
toscana         https://www.regione.toscana.it/bollettino-ufficiale
veneto          https://bur.regione.veneto.it/BurvServices/pubblica/HomeBurv.aspx
```

Esempio per la prima regione:

```json
    {
      "slug": "abruzzo",
      "nome": "Abruzzo",
      "calendario_ufficiale": "https://pesca.regione.abruzzo.it/fondo-europeo-feampa-2021-2027/calendario-avvisi",
      "bur": "https://bura.regione.abruzzo.it/",
      "flag": [
        {
          "nome": "GAL Pesca Abruzzo",
          "url": "https://galpescaabruzzo.it/download-category/bandi/"
        }
      ]
    },
```

- [ ] **Step 4: Renderlo in pagina**

In `bandi.php`, nell'intestazione di sezione, subito dopo il link `sito della Regione ↗` (intorno alla riga 294), aggiungi:

```php
        <?php if (!empty($s['cfg']['bur'])): ?>
        <?php /* Il Bollettino Ufficiale e' la pubblicazione legale dell'avviso:
                 quando l'aggregatore e la Regione divergono, e' qui che si
                 verifica chi ha ragione. Non se ne leggono le voci - nessun BUR
                 espone un feed - quindi resta un collegamento, come i FLAG che
                 non ne hanno uno. */ ?>
        <span class="bur-link">· <a class="bur" href="<?= h($s['cfg']['bur']) ?>" target="_blank" rel="noopener">Bollettino Ufficiale ↗</a></span>
        <?php endif; ?>
```

- [ ] **Step 5: Eseguire il test e verificare che passi**

Esegui: `php tests/run.php`
Atteso: `0 falliti`.

- [ ] **Step 6: Aggiornare il testo che descrive le fonti**

In `bandi.php`, nel paragrafo che spiega la provenienza delle voci, aggiungi la frase sui BUR dopo quella sul sito della Regione, così la pagina dichiara cosa copre e cosa no:

```
Il Bollettino Ufficiale di ogni Regione è collegato in testa alla sezione: è la pubblicazione legale dell'avviso, ma non espone un elenco leggibile automaticamente, quindi le sue voci non compaiono qui.
```

- [ ] **Step 7: Commit**

```bash
git add data/bandi_regioni.json bandi.php tests/test_pagine_markup.php
git commit -m "feat(bandi): il Bollettino Ufficiale di ogni Regione e' collegato in testa alla sezione

Il BUR e' la pubblicazione legale dell'avviso: quando l'aggregatore privato e la
Regione divergono, e' li' che si verifica chi ha ragione. Nessun BUR espone un
elenco leggibile - il Veneto e' un'applicazione ASPX, la Sicilia risponde 404 -
quindi resta un collegamento, la stessa scelta gia' fatta per i FLAG senza feed.

Le regioni senza sbocco al mare non lo ricevono: un link inutile e' rumore. La
pagina dichiara esplicitamente che le voci del BUR non compaiono in elenco.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 8: Documentazione e messa in esercizio

**Files:**
- Modify: `index.php` (nota a piè di pagina)
- Modify: `bandi.php` (paragrafo sulle origini)

**Interfaces:**
- Consumes: tutto quanto sopra.
- Produces: niente codice.

- [ ] **Step 1: Dichiarare la nuova fonte nel colophon del registro**

In `index.php`, nella `<footer class="note">`, aggiungi dopo la frase che cita `scraper.php`:

```
Gli atti pubblicati in Gazzetta Ufficiale sono raccolti da <code>gazzetta_fetcher.php</code>, che legge il sommario dell'ultimo fascicolo di ogni serie seguita e ne tiene le voci in tema. Il feed non è un archivio: quando un'esecuzione salta dei fascicoli, i numeri non visti restano registrati in <code>data/gazzetta.json</code> e nel log.
```

- [ ] **Step 2: Verificare che le pagine non emettano avvisi**

```bash
php -d error_reporting=E_ALL -d display_errors=1 index.php > /dev/null
php -d error_reporting=E_ALL -d display_errors=1 bandi.php > /dev/null
php -d error_reporting=E_ALL -d display_errors=1 news.php > /dev/null
```

Atteso: nessun output su stderr per nessuna delle tre.

- [ ] **Step 3: Eseguire la suite completa**

Esegui: `php tests/run.php`
Atteso: `0 falliti`.

- [ ] **Step 4: Commit**

```bash
git add index.php bandi.php
git commit -m "docs: le pagine dichiarano la Gazzetta Ufficiale fra le proprie fonti

Il colophon del registro spiega che il feed GU e' il sommario di un fascicolo e
non un archivio, e che i numeri saltati restano registrati: chi legge deve poter
sapere quanto e' completa la raccolta che sta guardando.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Verifica finale

Dopo l'ultimo task:

1. `php tests/run.php` → `0 falliti`
2. `php gazzetta_fetcher.php` → esce 0, log leggibile, `data/gazzetta.json` valido
3. `php check_allegati.php` → invariato rispetto a prima del piano (questo lavoro non tocca gli allegati)
4. Le tre pagine si generano senza avvisi PHP

## Fuori perimetro

Deliberatamente non incluso, come da spec:

- **scraping dei BUR**: quindici parser fragili, si collegano soltanto
- **recupero dei fascicoli saltati**: si segnalano, non si ricostruiscono
- **serie diverse dalla Generale**: si aggiungono a `data/gazzetta_fonti.json` senza toccare il codice
- **5ª Serie Contratti Pubblici**: pubblica appalti, che sono un tipo di bando diverso dai contributi alle imprese di pesca
