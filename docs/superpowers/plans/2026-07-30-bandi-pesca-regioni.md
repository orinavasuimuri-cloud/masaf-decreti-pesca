# Bandi pesca per regione — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aggiungere al sito una pagina `bandi.php` con tutti i bandi che riguardano la pesca organizzati per regione, con i bandi aperti in evidenza e l'archivio dei chiusi consultabile.

**Architecture:** Terza unità isolata, identica per forma alle due esistenti. `bandi_fetcher.php` (CLI, Task Scheduler giornaliero) interroga l'aggregatore FEAMPA e tre feed istituzionali, normalizza e salva in `data/bandi.json`; `bandi.php` legge quel file e disegna, senza alcun accesso di rete. La logica sta in tre librerie sotto `lib/`: normalizzazione, parsing, persistenza.

**Tech Stack:** PHP 8.3 (nessun framework, nessuna dipendenza), `curl.exe` di sistema per il fetch, DOMDocument + XPath per l'HTML dell'archivio, SimpleXML per i feed, `iconv` per le conversioni di charset.

**Spec di riferimento:** `docs/superpowers/specs/2026-07-30-bandi-pesca-regioni-design.md`

## Global Constraints

- PHP 8.3 senza `mbstring`, `openssl`, estensione `curl`, `intl`. Disponibili: `iconv`, `simplexml`, `dom`, `libxml`, `json`.
- Nessuna libreria esterna, nessun composer: il progetto non ha né deve acquisire dipendenze installabili.
- Il fetch HTTPS passa **sempre** da `shell_exec` su `curl.exe`, mai da `file_get_contents`: senza `openssl` i wrapper `https://` non esistono. Riferimento: `scraper.php:36-47`, `news_fetcher.php`.
- Timeout 25 secondi per richiesta; User-Agent `Mozilla/5.0 (Windows NT 10.0; Win64; x64) masaf-decreti-pesca-scraper/1.0`.
- Ogni stringa proveniente dalla rete passa da `news_to_utf8()` prima di finire in un array destinato a `json_encode`.
- Tutti i file JSON si scrivono con `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.
- Testo dell'interfaccia in italiano, con accenti corretti (`è`, `à`, `°`).
- **Nessuna potatura**: a differenza delle news, l'archivio dei bandi chiusi si conserva integralmente. `bandi_store_*` non ha una funzione di prune e non deve acquisirla.
- Non si modificano `scraper.php`, `news_fetcher.php`, `lib/news_*.php`, né la logica di `index.php` che legge `catalog.json`/`known.json`. Di `index.php` e `news.php` si tocca solo la topbar.
- Le funzioni nuove hanno prefisso `bandi_`. Si riusano senza duplicarle: `news_to_utf8()`, `news_normalize_url()`, `news_item_id()`, `news_clean_summary()`, `news_parse_rss()`.

---

## File Structure

| File | Responsabilità |
|---|---|
| `lib/bandi_normalize.php` | Funzioni pure: date italiane, stato, titolo dallo scopo, slug regioni, testo. Nessun I/O. |
| `lib/bandi_parser.php` | Da HTML/JSON/RSS grezzo a voci normalizzate. Nessun I/O di rete. |
| `lib/bandi_store.php` | Caricamento, merge, copertura e salvataggio di `data/bandi.json`. Nessuna potatura. |
| `bandi_fetcher.php` | Orchestratore CLI: API, archivio per regione, feed istituzionali, salvataggio. |
| `data/bandi_regioni.json` | Dato curato: 20 regioni, calendario ufficiale, FLAG del territorio. |
| `data/bandi_fonti.json` | Configurazione delle fonti (dato, non codice). |
| `data/bandi.json` | Stato: voci + salute per fonte + copertura. Generato. |
| `bandi.php` | Rendering della pagina bandi. Sola lettura. |
| `assets/style.css` | Modificato: stili della pagina bandi in coda. |
| `index.php`, `news.php` | Modificati: solo il link nella topbar. |
| `tests/test_bandi_*.php` | Test per libreria, raccolti dal runner esistente. |
| `tests/fixtures/bandi_archivio_toscana.html` | Pagina archivio reale congelata. |

---

### Task 1: Normalizzazione — date italiane, stato, titolo, slug

**Files:**
- Create: `lib/bandi_normalize.php`, `tests/test_bandi_normalize.php`

**Interfaces:**
- Consumes: niente
- Produces:
  - `bandi_testo(string $html): string` — strip tag, decodifica entità, normalizza spazi **e nbsp**
  - `bandi_parse_data_italiana(string $raw): ?string` — `"15 Luglio 2026"` → `"2026-07-15"`, `null` se non interpretabile
  - `bandi_stato(?string $scadenza, bool $terminatoInFonte, string $oggi): string` — `aperto` | `chiuso` | `da_verificare`
  - `bandi_titolo_da_scopo(string $scopo, int $max = 90): string`
  - `bandi_regioni_da_classi(string $classAttr): array` — lista di slug, esclusi `terminato` e `senza-categoria`
  - `bandi_e_terminato(string $classAttr): bool`

- [ ] **Step 1: Scrivere i test che falliscono**

`tests/test_bandi_normalize.php`:

```php
<?php
require_once __DIR__ . '/../lib/bandi_normalize.php';

// --- bandi_testo: il nbsp è il punto critico ---
// Nell'archivio i valori arrivano attaccati all'etichetta con un U+00A0:
// senza normalizzarlo "15 Luglio 2026" resta "\u{A0}15 Luglio 2026" e la data non si legge.
t_eq(bandi_testo("\u{A0}15 Luglio 2026"), '15 Luglio 2026', 'nbsp iniziale rimosso');
t_eq(bandi_testo('<p>Ciao   <b>mondo</b></p>'), 'Ciao mondo', 'tag via, spazi compattati');
t_eq(bandi_testo('Pesca &amp; acquacoltura'), 'Pesca & acquacoltura', 'entità decodificate');
t_eq(bandi_testo('attività&#8217;'), 'attività’', 'entità numeriche decodificate');
t_eq(bandi_testo('   '), '', 'solo spazi diventa stringa vuota');

// --- bandi_parse_data_italiana ---
t_eq(bandi_parse_data_italiana('15 Luglio 2026'), '2026-07-15', 'data italiana standard');
t_eq(bandi_parse_data_italiana('10 Giugno 2026'), '2026-06-10', 'mese con maiuscola');
t_eq(bandi_parse_data_italiana('1 gennaio 2027'), '2027-01-01', 'giorno a una cifra, mese minuscolo');
t_eq(bandi_parse_data_italiana("\u{A0} 31 Dicembre 2026 "), '2026-12-31', 'nbsp e spazi ignorati');
t_eq(bandi_parse_data_italiana(''), null, 'stringa vuota');
t_eq(bandi_parse_data_italiana('prossimamente'), null, 'testo libero');
t_eq(bandi_parse_data_italiana('32 Luglio 2026'), null, 'giorno inesistente');
t_eq(bandi_parse_data_italiana('15 Luglione 2026'), null, 'mese inesistente');

// --- bandi_stato ---
$oggi = '2026-07-30';
t_eq(bandi_stato('2026-08-15', false, $oggi), 'aperto', 'scadenza futura');
t_eq(bandi_stato('2026-07-30', false, $oggi), 'aperto', 'scadenza oggi: ancora aperto');
t_eq(bandi_stato('2026-07-29', false, $oggi), 'chiuso', 'scadenza passata');
// La scadenza vince sulla categoria: la fonte marca "Terminato" con giorni di ritardo.
t_eq(bandi_stato('2026-08-15', true, $oggi), 'aperto', 'scadenza futura anche se marcato terminato');
t_eq(bandi_stato(null, true, $oggi), 'chiuso', 'senza scadenza, marcato terminato');
t_eq(bandi_stato(null, false, $oggi), 'da_verificare', 'senza scadenza, non marcato');
t_eq(bandi_stato('', false, $oggi), 'da_verificare', 'scadenza vuota equivale ad assente');

// --- bandi_titolo_da_scopo ---
$scopo = 'L’azione «Salute e compatibilità ambientale dei prodotti dell’acquacoltura» è '
       . 'finalizzata a promuovere un’acquacoltura in grado di soddisfare rigorose condizioni.';
t_eq(
    bandi_titolo_da_scopo($scopo),
    'Salute e compatibilità ambientale dei prodotti dell’acquacoltura',
    'il nome dell’azione fra virgolette diventa il titolo'
);
$senzaVirgolette = 'Sostegno agli investimenti a bordo dei pescherecci per migliorare la sicurezza '
                 . 'e le condizioni di lavoro, con particolare riguardo alla flotta artigianale.';
$t = bandi_titolo_da_scopo($senzaVirgolette, 90);
t_true(strlen($t) <= 94, 'senza virgolette: troncato entro il limite');
t_true(str_ends_with($t, '…'), 'senza virgolette: ellissi finale');
t_true(str_starts_with($t, 'Sostegno agli investimenti'), 'senza virgolette: inizio conservato');
t_eq(bandi_titolo_da_scopo('Contributo breve.'), 'Contributo breve.', 'testo corto invariato');
t_eq(bandi_titolo_da_scopo(''), '', 'scopo vuoto');

// --- bandi_regioni_da_classi ---
$cls = 'et_pb_post post-1376 post type-post status-publish hentry category-terminato category-toscana';
t_eq(bandi_regioni_da_classi($cls), ['toscana'], 'terminato escluso, resta la regione');
t_true(bandi_e_terminato($cls), 'categoria terminato riconosciuta');
$due = 'et_pb_post post-9 hentry category-puglia category-basilicata';
t_eq(bandi_regioni_da_classi($due), ['puglia', 'basilicata'], 'due regioni conservate entrambe');
t_true(!bandi_e_terminato($due), 'senza categoria terminato');
t_eq(bandi_regioni_da_classi('et_pb_post post-3 hentry category-senza-categoria'), [],
     'senza-categoria non è una regione');
t_eq(bandi_regioni_da_classi('et_pb_post post-4 hentry category-bandi-masaf-nazionali'),
     ['bandi-masaf-nazionali'], 'la categoria nazionale passa: la pagina la tratta a parte');
t_eq(bandi_regioni_da_classi('et_pb_post post-5 hentry'), [], 'nessuna categoria');
```

- [ ] **Step 2: Eseguire i test e verificare che falliscano**

```bash
cd "C:/Users/giang/Progetti Claude/masaf-decreti-pesca"
php tests/run.php
```

Atteso: errore fatale `Failed opening required '.../lib/bandi_normalize.php'`.

- [ ] **Step 3: Implementare `lib/bandi_normalize.php`**

```php
<?php
declare(strict_types=1);

/**
 * Funzioni pure per i bandi. Nessun accesso a rete o disco: sono la base
 * testabile su cui poggiano parser, store e pagina.
 */

/**
 * Testo leggibile da un frammento HTML. Oltre a tag ed entità normalizza lo
 * spazio unificatore U+00A0, che nell'archivio separa l'etichetta dal valore:
 * senza questa sostituzione le date non sono interpretabili.
 */
function bandi_testo(string $html): string {
    $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = (string) preg_replace('/[\x{00A0}\s]+/u', ' ', $t);
    return trim($t);
}

/**
 * "15 Luglio 2026" -> "2026-07-15". Senza intl la mappa dei mesi è scritta a
 * mano; i nomi italiani sono ASCII puri, quindi strtolower basta.
 * Restituisce null su input non interpretabile: chi chiama decide il fallback.
 */
function bandi_parse_data_italiana(string $raw): ?string {
    $raw = bandi_testo($raw);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/u', $raw, $m) !== 1) {
        return null;
    }
    $mesi = [
        'gennaio' => 1, 'febbraio' => 2, 'marzo' => 3, 'aprile' => 4,
        'maggio' => 5, 'giugno' => 6, 'luglio' => 7, 'agosto' => 8,
        'settembre' => 9, 'ottobre' => 10, 'novembre' => 11, 'dicembre' => 12,
    ];
    $mese = $mesi[strtolower($m[2])] ?? null;
    if ($mese === null) {
        return null;
    }
    $giorno = (int) $m[1];
    $anno = (int) $m[3];
    if (!checkdate($mese, $giorno, $anno)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $anno, $mese, $giorno);
}

/**
 * Lo stato non si copia dalla fonte: si calcola. La categoria "Terminato"
 * dell'aggregatore arriva con giorni di ritardo, quindi decide solo quando la
 * scadenza manca. Le date sono ISO, il confronto fra stringhe è corretto.
 */
function bandi_stato(?string $scadenza, bool $terminatoInFonte, string $oggi): string {
    if ($scadenza !== null && $scadenza !== '') {
        return $scadenza >= $oggi ? 'aperto' : 'chiuso';
    }
    return $terminatoInFonte ? 'chiuso' : 'da_verificare';
}

/**
 * Sull'aggregatore il titolo del post è la priorità FEAMPA, uguale per decine
 * di bandi. Il titolo utile sta nello scopo: quando c'è, è il nome dell'azione
 * fra virgolette; altrimenti si tronca l'inizio della prima frase.
 */
function bandi_titolo_da_scopo(string $scopo, int $max = 90): string {
    $s = bandi_testo($scopo);
    if ($s === '') {
        return '';
    }
    if (preg_match('/[«"“„](.{10,120}?)[»"“”]/u', $s, $m) === 1) {
        return trim($m[1]);
    }
    if (strlen($s) <= $max) {
        return $s;
    }
    if (preg_match('/^(.{20,' . $max . '})(?=[\s.,;:])/u', $s, $m) === 1) {
        return rtrim($m[1], " ,.;:") . '…';
    }
    return $s;
}

/**
 * Le categorie stanno nelle classi dell'<article>: "category-terminato
 * category-toscana". Un post può portarne più d'una, quindi si restituisce una
 * lista: la voce comparirà nella sezione di ogni regione indicata.
 */
function bandi_regioni_da_classi(string $classAttr): array {
    if (preg_match_all('/\bcategory-([a-z0-9\-]+)\b/', $classAttr, $m) === 0) {
        return [];
    }
    $slug = [];
    foreach ($m[1] as $s) {
        if ($s === 'terminato' || $s === 'senza-categoria') {
            continue;
        }
        $slug[$s] = true;
    }
    return array_keys($slug);
}

function bandi_e_terminato(string $classAttr): bool {
    return preg_match('/\bcategory-terminato\b/', $classAttr) === 1;
}
```

- [ ] **Step 4: Eseguire i test e verificare che passino**

```bash
php tests/run.php
```

Atteso: i test esistenti più 30 nuovi passati, `0 falliti`, exit code 0.

- [ ] **Step 5: Commit**

```bash
git add lib/bandi_normalize.php tests/test_bandi_normalize.php
git commit -m "feat: normalizzazione date italiane, stato e titolo dei bandi"
```

---

### Task 2: Parser dell'archivio, delle categorie e dei feed

**Files:**
- Create: `lib/bandi_parser.php`, `tests/test_bandi_parser.php`, `tests/fixtures/bandi_archivio_toscana.html`, `tests/fixtures/bandi_categorie.json`

**Interfaces:**
- Consumes: tutto Task 1, più `news_to_utf8()`, `news_normalize_url()`, `news_item_id()`, `news_clean_summary()` da `lib/news_normalize.php` e `news_parse_rss()` da `lib/news_parsers.php`
- Produces:
  - `bandi_parse_archivio(string $html, string $fonteId = 'aggregatore'): array` — lista di voci; lancia `RuntimeException` se non trova articoli
  - `bandi_parse_categorie(string $json): array` — `['toscana' => 18, …]`
  - `bandi_da_feed(array $items, string $regioneSlug, array $keywords): array` — adatta le voci di `news_parse_rss()` alla forma bando
  - Forma di una voce (usata da Task 3, 5 e 6):
    `['id','wp_id','origine','regioni','priorita','titolo','scopo','codice_intervento','pubblicazione','scadenza','terminato_in_fonte','nota','url_fonte','url_ufficiale','dettagli_mancanti']`

- [ ] **Step 1: Scaricare le fixture reali**

```bash
cd "C:/Users/giang/Progetti Claude/masaf-decreti-pesca"
curl -s -A "Mozilla/5.0" --max-time 25 "https://www.feampabandionline.it/regione/toscana/" -o tests/fixtures/bandi_archivio_toscana.html
curl -s -A "Mozilla/5.0" --max-time 25 "https://www.feampabandionline.it/wp-json/wp/v2/categories?per_page=100" -o tests/fixtures/bandi_categorie.json
wc -c tests/fixtures/bandi_archivio_toscana.html tests/fixtures/bandi_categorie.json
```

Atteso: entrambi non vuoti (l'HTML supera i 100 KB). La fixture congela la forma reale della fonte: un cambio di markup si manifesta come test rosso invece che come pagina vuota in produzione.

- [ ] **Step 2: Scrivere i test che falliscono**

`tests/test_bandi_parser.php`:

```php
<?php
require_once __DIR__ . '/../lib/bandi_parser.php';

// --- archivio ---
$html = file_get_contents(__DIR__ . '/fixtures/bandi_archivio_toscana.html');
$voci = bandi_parse_archivio($html);

t_eq(count($voci), 10, 'archivio: 10 voci per pagina');

$v = $voci[0];
t_eq($v['origine'], 'aggregatore', 'archivio: origine valorizzata');
t_eq(strlen($v['id']), 40, 'archivio: id sha1');
t_true($v['wp_id'] > 0, 'archivio: wp_id estratto da id="post-N"');
t_true(in_array('toscana', $v['regioni'], true), 'archivio: regione dalla classe');
t_true(!in_array('terminato', $v['regioni'], true), 'archivio: terminato non è una regione');
t_true(str_starts_with($v['url_fonte'], 'https://www.feampabandionline.it/'), 'archivio: permalink assoluto');
t_true($v['titolo'] !== '', 'archivio: titolo non vuoto');
t_true($v['priorita'] !== '', 'archivio: priorità conservata come etichetta');
t_true($v['titolo'] !== $v['priorita'], 'archivio: il titolo non è la priorità');

// Almeno una voce della pagina deve avere scadenza e codice: se il markup cambia
// questi due campi sono i primi a sparire, e sono quelli che danno valore alla pagina.
$conScadenza = array_filter($voci, static fn(array $x): bool => $x['scadenza'] !== null);
$conCodice = array_filter($voci, static fn(array $x): bool => $x['codice_intervento'] !== '');
t_true(count($conScadenza) >= 5, 'archivio: almeno metà delle voci ha la scadenza');
t_true(count($conCodice) >= 5, 'archivio: almeno metà delle voci ha il codice di intervento');

foreach ($voci as $x) {
    if ($x['scadenza'] !== null) {
        t_true(preg_match('/^\d{4}-\d{2}-\d{2}$/', $x['scadenza']) === 1, 'archivio: scadenza ISO');
    }
    if ($x['pubblicazione'] !== null) {
        t_true(preg_match('/^\d{4}-\d{2}-\d{2}$/', $x['pubblicazione']) === 1, 'archivio: pubblicazione ISO');
    }
}

$ids = array_column($voci, 'id');
t_eq(count($ids), count(array_unique($ids)), 'archivio: nessun id duplicato');
t_true(json_encode($voci) !== false, 'archivio: risultato codificabile in JSON');

// Il link al decreto regionale, quando presente, non deve puntare all'aggregatore.
foreach ($voci as $x) {
    if ($x['url_ufficiale'] !== null) {
        t_true(!str_contains($x['url_ufficiale'], 'feampabandionline.it'),
               'archivio: url_ufficiale è esterno alla fonte');
    }
}

// --- degrado controllato: markup senza i campi attesi ---
$rotto = '<html><body><article id="post-99" class="et_pb_post post-99 hentry category-lazio">'
       . '<h2 class="entry-title"><a href="https://www.feampabandionline.it/x/">Priorità 1</a></h2>'
       . '</article></body></html>';
$degradato = bandi_parse_archivio($rotto);
t_eq(count($degradato), 1, 'degrado: la voce resta anche senza campi');
t_eq($degradato[0]['scadenza'], null, 'degrado: scadenza nulla');
t_true($degradato[0]['dettagli_mancanti'], 'degrado: marcata come incompleta');
t_eq($degradato[0]['titolo'], 'Priorità 1', 'degrado: senza scopo il titolo ricade sulla priorità');

// --- pagina senza articoli: errore, non silenzio ---
$caught = false;
try { bandi_parse_archivio('<html><body><p>vuoto</p></body></html>'); }
catch (RuntimeException) { $caught = true; }
t_true($caught, 'archivio: pagina senza articoli solleva RuntimeException');

// --- categorie ---
$json = file_get_contents(__DIR__ . '/fixtures/bandi_categorie.json');
$cat = bandi_parse_categorie($json);
t_true(isset($cat['toscana']), 'categorie: slug regione presente');
t_true($cat['toscana'] > 0, 'categorie: conteggio positivo');
t_true(isset($cat['terminato']), 'categorie: terminato presente per la riconciliazione');
$caught2 = false;
try { bandi_parse_categorie('non json'); } catch (RuntimeException) { $caught2 = true; }
t_true($caught2, 'categorie: JSON non valido solleva RuntimeException');

// --- feed istituzionali ---
$feedItems = [
    ['id' => 'a1', 'source' => 'f', 'title' => 'Bando pesca costiera artigianale',
     'url' => 'https://x.it/a1', 'date' => '2026-07-20T10:00:00+02:00', 'summary' => 'Estratto'],
    ['id' => 'a2', 'source' => 'f', 'title' => 'Concorso per bibliotecari',
     'url' => 'https://x.it/a2', 'date' => '2026-07-21T10:00:00+02:00', 'summary' => ''],
];
$filtrati = bandi_da_feed($feedItems, 'calabria', ['pesca', 'ittic']);
t_eq(count($filtrati), 1, 'feed: filtro per parole chiave applicato');
t_eq($filtrati[0]['origine'], 'istituzionale', 'feed: origine istituzionale');
t_eq($filtrati[0]['regioni'], ['calabria'], 'feed: regione dalla configurazione');
t_eq($filtrati[0]['scadenza'], null, 'feed: nessuna scadenza dichiarata');
t_true($filtrati[0]['dettagli_mancanti'], 'feed: segnalazione, non scheda completa');
t_eq($filtrati[0]['pubblicazione'], '2026-07-20', 'feed: data ridotta al giorno');
t_eq($filtrati[0]['titolo'], 'Bando pesca costiera artigianale', 'feed: titolo conservato');

$tutti = bandi_da_feed($feedItems, 'basilicata', []);
t_eq(count($tutti), 2, 'feed: senza parole chiave passano tutte le voci');
```

- [ ] **Step 3: Eseguire i test e verificare che falliscano**

```bash
php tests/run.php
```

Atteso: errore fatale su `lib/bandi_parser.php` mancante.

- [ ] **Step 4: Implementare `lib/bandi_parser.php`**

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/news_normalize.php';
require_once __DIR__ . '/bandi_normalize.php';

/**
 * Forma canonica di una voce. Unico punto in cui si definisce la struttura:
 * archivio e feed producono array identici, così store e pagina non devono
 * sapere da dove arriva il dato.
 */
function bandi_voce(array $campi): array {
    return [
        'id'                 => $campi['id'],
        'wp_id'              => $campi['wp_id'] ?? 0,
        'origine'            => $campi['origine'],
        'regioni'            => $campi['regioni'] ?? [],
        'priorita'           => $campi['priorita'] ?? '',
        'titolo'             => $campi['titolo'] ?? '',
        'scopo'              => $campi['scopo'] ?? '',
        'codice_intervento'  => $campi['codice_intervento'] ?? '',
        'pubblicazione'      => $campi['pubblicazione'] ?? null,
        'scadenza'           => $campi['scadenza'] ?? null,
        'terminato_in_fonte' => $campi['terminato_in_fonte'] ?? false,
        'nota'               => $campi['nota'] ?? '',
        'url_fonte'          => $campi['url_fonte'],
        'url_ufficiale'      => $campi['url_ufficiale'] ?? null,
        'dettagli_mancanti'  => $campi['dettagli_mancanti'] ?? false,
    ];
}

/**
 * Estrae le voci da una pagina /regione/<slug>/ dell'aggregatore.
 *
 * I campi non stanno in elementi con classi proprie: sono testo che segue
 * <strong>etichetta</strong> dentro un unico contenitore Divi. Si individua
 * quindi l'<article> con XPath e si leggono i campi con espressioni regolari
 * sul suo frammento HTML. Un campo mancante non fa saltare la voce: resta con
 * i dati minimi e dettagli_mancanti a true.
 */
function bandi_parse_archivio(string $html, string $fonteId = 'aggregatore'): array {
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $loaded = $dom->loadHTML(
        '<?xml encoding="UTF-8">' . news_to_utf8($html),
        LIBXML_NOWARNING | LIBXML_NOERROR
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if ($loaded === false) {
        throw new RuntimeException('HTML archivio non parsabile');
    }

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//article[contains(@class, "et_pb_post")]');
    if ($nodes === false || $nodes->length === 0) {
        throw new RuntimeException('Nessun articolo trovato: struttura archivio cambiata');
    }

    $voci = [];
    foreach ($nodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $frag = (string) $dom->saveHTML($node);
        $class = $node->getAttribute('class');

        $link = $xpath->query('.//h2[contains(@class, "entry-title")]//a', $node);
        if ($link === false || $link->length === 0) {
            continue; // senza permalink non esiste un id stabile
        }
        $anchor = $link->item(0);
        $permalink = trim($anchor->getAttribute('href'));
        if ($permalink === '') {
            continue;
        }
        $priorita = bandi_testo($anchor->textContent);

        $wpId = 0;
        if (preg_match('/^post-(\d+)$/', $node->getAttribute('id'), $m) === 1) {
            $wpId = (int) $m[1];
        }

        $scopo = '';
        if (preg_match('#Scopo\s+Contributo:?\s*</strong>(.*?)(?=<strong|<!--)#is', $frag, $m) === 1) {
            $scopo = news_clean_summary(bandi_testo($m[1]), 300);
        }

        $codice = '';
        if (preg_match('#Codice\s+di\s+intervento\s*</strong>\s*<p[^>]*>(.*?)</p>#is', $frag, $m) === 1) {
            $codice = bandi_testo($m[1]);
        }

        $pubblicazione = null;
        if (preg_match('#Data\s+di\s+pubblicazione:?\s*</strong>([^<]*)#i', $frag, $m) === 1) {
            $pubblicazione = bandi_parse_data_italiana($m[1]);
        }

        $scadenza = null;
        if (preg_match('#Data\s+di\s+scadenza:?\s*</strong>([^<]*)#i', $frag, $m) === 1) {
            $scadenza = bandi_parse_data_italiana($m[1]);
        }

        // Le proroghe stanno in coda alla scadenza, prima del blocco "Leggi tutto".
        $nota = '';
        if (preg_match('#Data\s+di\s+scadenza:?\s*</strong>[^<]*(.*?)(?=<div|</article)#is', $frag, $m) === 1) {
            $nota = bandi_testo($m[1]);
            // L'URL citato nella nota diventa url_ufficiale: nel testo è rumore.
            $nota = trim((string) preg_replace('#https?://\S+#', '', $nota));
            $nota = trim(str_replace('Leggi tutto', '', $nota));
        }

        $ufficiale = null;
        $links = $xpath->query('.//a[@href]', $node);
        foreach ($links as $a) {
            $href = trim($a->getAttribute('href'));
            if (!str_starts_with($href, 'http') || str_contains($href, 'feampabandionline.it')) {
                continue;
            }
            $ufficiale = news_normalize_url($href);
            break;
        }

        $titolo = bandi_titolo_da_scopo($scopo);

        $voci[news_item_id($permalink)] = bandi_voce([
            'id'                 => news_item_id($permalink),
            'wp_id'              => $wpId,
            'origine'            => $fonteId,
            'regioni'            => bandi_regioni_da_classi($class),
            'priorita'           => $priorita,
            'titolo'             => $titolo !== '' ? $titolo : $priorita,
            'scopo'              => $scopo,
            'codice_intervento'  => $codice,
            'pubblicazione'      => $pubblicazione,
            'scadenza'           => $scadenza,
            'terminato_in_fonte' => bandi_e_terminato($class),
            'nota'               => $nota,
            'url_fonte'          => news_normalize_url($permalink),
            'url_ufficiale'      => $ufficiale,
            // Se mancano sia scadenza sia codice il parsing dei campi non ha
            // funzionato: la voce resta, ma la pagina la segnala come parziale.
            'dettagli_mancanti'  => $scadenza === null && $codice === '',
        ]);
    }

    return array_values($voci);
}

/** Conteggi per slug dall'API categories, usati per riconciliare la copertura. */
function bandi_parse_categorie(string $json): array {
    $data = json_decode($json, true);
    if (!is_array($data)) {
        throw new RuntimeException('Risposta categories non valida');
    }
    $out = [];
    foreach ($data as $c) {
        if (isset($c['slug'], $c['count'])) {
            $out[(string) $c['slug']] = (int) $c['count'];
        }
    }
    return $out;
}

/**
 * Adatta le voci di news_parse_rss() alla forma bando. I feed istituzionali
 * sono feed di sito, non di elenco bandi: danno titolo, data e link, mai la
 * scadenza. Restano quindi segnalazioni, marcate come incomplete.
 */
function bandi_da_feed(array $items, string $regioneSlug, array $keywords): array {
    $pattern = $keywords === []
        ? ''
        : '/' . implode('|', array_map('preg_quote', $keywords)) . '/iu';

    $voci = [];
    foreach ($items as $item) {
        $titolo = bandi_testo((string) $item['title']);
        if ($titolo === '') {
            continue;
        }
        if ($pattern !== '' && preg_match($pattern, $titolo) !== 1) {
            continue;
        }
        $url = (string) $item['url'];
        $voci[news_item_id($url)] = bandi_voce([
            'id'                => news_item_id($url),
            'origine'           => 'istituzionale',
            'regioni'           => [$regioneSlug],
            'titolo'            => $titolo,
            'scopo'             => news_clean_summary((string) ($item['summary'] ?? ''), 300),
            'pubblicazione'     => substr((string) $item['date'], 0, 10),
            'scadenza'          => null,
            'nota'              => '',
            'url_fonte'         => news_normalize_url($url),
            'dettagli_mancanti' => true,
        ]);
    }
    return array_values($voci);
}
```

- [ ] **Step 5: Eseguire i test e verificare che passino**

```bash
php tests/run.php
```

Atteso: `0 falliti`, exit code 0.

Se `archivio: 10 voci per pagina` fallisce con un numero diverso, **non correggere il test al volo**: verificare prima con `curl` quante voci mostra davvero la pagina Toscana. Il conteggio di 10 è quello osservato il 2026-07-30 e la paginazione è la cosa che il fetcher usa per sapere quando fermarsi.

- [ ] **Step 6: Commit**

```bash
git add lib/bandi_parser.php tests/test_bandi_parser.php tests/fixtures/bandi_archivio_toscana.html tests/fixtures/bandi_categorie.json
git commit -m "feat: parser dell'archivio bandi, delle categorie e dei feed istituzionali"
```

---

### Task 3: Store — merge non distruttivo, copertura, salvataggio verificato

**Files:**
- Create: `lib/bandi_store.php`, `tests/test_bandi_store.php`

**Interfaces:**
- Consumes: niente (opera su array già normalizzati)
- Produces:
  - `bandi_store_empty(): array`
  - `bandi_store_load(string $path): array`
  - `bandi_store_merge(array $store, string $fonteId, array $voci, string $nowIso): array`
  - `bandi_store_mark_failure(array $store, string $fonteId, string $errore, string $nowIso): array`
  - `bandi_store_set_copertura(array $store, int $attesiApi, array $perRegione): array`
  - `bandi_fonte_is_stale(array $store, string $fonteId, string $nowIso, int $giorni = 7): bool`
  - `bandi_store_save(string $path, array $store): void` — lancia `RuntimeException` se `json_encode` fallisce

- [ ] **Step 1: Scrivere i test che falliscono**

`tests/test_bandi_store.php`:

```php
<?php
require_once __DIR__ . '/../lib/bandi_store.php';

$now = '2026-07-30T09:00:00+02:00';
$mk = static fn(string $id, array $extra = []): array => array_merge([
    'id' => $id, 'wp_id' => 0, 'origine' => 'aggregatore', 'regioni' => ['toscana'],
    'priorita' => 'P1', 'titolo' => "t$id", 'scopo' => '', 'codice_intervento' => '',
    'pubblicazione' => '2026-06-01', 'scadenza' => '2026-08-01', 'terminato_in_fonte' => false,
    'nota' => '', 'url_fonte' => "https://x.it/$id", 'url_ufficiale' => null,
    'dettagli_mancanti' => false,
], $extra);

// --- load su file assente ---
$empty = bandi_store_load(__DIR__ . '/fixtures/non-esiste.json');
t_eq($empty['items'], [], 'load: items vuoto se il file manca');
t_true(isset($empty['_meta']['fonti']), 'load: _meta.fonti presente');
t_true(isset($empty['_meta']['copertura']), 'load: _meta.copertura presente');

// --- merge aggiunge ---
$s = bandi_store_merge($empty, 'aggregatore', [$mk('a'), $mk('b')], $now);
t_eq(count($s['items']), 2, 'merge: due voci aggiunte');
t_eq($s['_meta']['fonti']['aggregatore']['consecutive_failures'], 0, 'merge: fallimenti azzerati');
t_eq($s['_meta']['fonti']['aggregatore']['last_ok'], $now, 'merge: last_ok registrato');

// --- merge non duplica ---
$s2 = bandi_store_merge($s, 'aggregatore', [$mk('a')], $now);
t_eq(count($s2['items']), 2, 'merge: id già presente non duplica');

// --- merge aggiorna i campi che possono cambiare ---
$s3 = bandi_store_merge($s2, 'aggregatore', [
    $mk('a', ['titolo' => 'Titolo corretto', 'scadenza' => '2026-09-15',
              'nota' => 'Bando prorogato', 'terminato_in_fonte' => true,
              'url_ufficiale' => 'https://regione.toscana.it/atto']),
], $now);
$a = null;
foreach ($s3['items'] as $it) { if ($it['id'] === 'a') { $a = $it; } }
t_eq($a['titolo'], 'Titolo corretto', 'merge: titolo aggiornato');
t_eq($a['scadenza'], '2026-09-15', 'merge: proroga recepita');
t_eq($a['nota'], 'Bando prorogato', 'merge: nota aggiornata');
t_true($a['terminato_in_fonte'], 'merge: stato in fonte aggiornato');
t_eq($a['url_ufficiale'], 'https://regione.toscana.it/atto', 'merge: link ufficiale aggiunto');
t_eq($a['pubblicazione'], '2026-06-01', 'merge: data di pubblicazione invariata');

// --- merge conserva le voci di altre fonti ---
$s4 = bandi_store_merge($s3, 'basilicata-rss', [$mk('c', ['origine' => 'istituzionale'])], $now);
t_eq(count($s4['items']), 3, 'merge: fonte diversa si somma');
$s5 = bandi_store_merge($s4, 'basilicata-rss', [], $now);
t_eq(count($s5['items']), 3, 'merge: fonte senza novità non cancella nulla');

// --- nessuna potatura: l'archivio dei chiusi si conserva ---
$vecchio = bandi_store_merge($empty, 'aggregatore', [
    $mk('storico', ['pubblicazione' => '2022-01-10', 'scadenza' => '2022-03-01']),
], $now);
t_eq(count($vecchio['items']), 1, 'nessuna potatura: la voce del 2022 resta');
t_true(!function_exists('bandi_store_prune'), 'nessuna funzione di potatura definita');

// --- fallimento non tocca gli items ---
$f = bandi_store_mark_failure($s5, 'aggregatore', 'HTTP 503', $now);
t_eq(count($f['items']), 3, 'failure: gli items restano');
t_eq($f['_meta']['fonti']['aggregatore']['consecutive_failures'], 1, 'failure: contatore a 1');
t_eq($f['_meta']['fonti']['aggregatore']['last_error'], 'HTTP 503', 'failure: errore registrato');
$f2 = bandi_store_mark_failure($f, 'aggregatore', 'HTTP 503', $now);
t_eq($f2['_meta']['fonti']['aggregatore']['consecutive_failures'], 2, 'failure: contatore incrementa');
$rec = bandi_store_merge($f2, 'aggregatore', [$mk('d')], $now);
t_eq($rec['_meta']['fonti']['aggregatore']['consecutive_failures'], 0, 'recupero: fallimenti a 0');
t_eq($rec['_meta']['fonti']['aggregatore']['last_error'], null, 'recupero: errore cancellato');

// --- staleness a 7 giorni ---
t_true(!bandi_fonte_is_stale($rec, 'aggregatore', $now), 'fonte aggiornata ora: non stale');
t_true(!bandi_fonte_is_stale($rec, 'aggregatore', '2026-08-05T09:00:00+02:00'), 'dopo 6 giorni: non stale');
t_true(bandi_fonte_is_stale($rec, 'aggregatore', '2026-08-07T10:00:00+02:00'), 'dopo 8 giorni: stale');
t_true(bandi_fonte_is_stale($rec, 'mai-vista', $now), 'fonte senza last_ok: stale');

// --- copertura ---
$cop = bandi_store_set_copertura($rec, 147, ['toscana' => 18, 'sicilia' => 18]);
t_eq($cop['_meta']['copertura']['attesi_api'], 147, 'copertura: attesi registrati');
t_eq($cop['_meta']['copertura']['raccolti'], count($cop['items']), 'copertura: raccolti = voci in archivio');
t_eq($cop['_meta']['copertura']['per_regione']['toscana'], 18, 'copertura: dettaglio per regione');

// --- save verificato ---
$tmp = sys_get_temp_dir() . '/bandi_test_' . getmypid() . '.json';
bandi_store_save($tmp, $cop);
t_true(file_exists($tmp), 'save: file creato');
$reread = bandi_store_load($tmp);
t_eq(count($reread['items']), count($cop['items']), 'save: rilettura coerente');
t_eq($reread['_meta']['copertura']['attesi_api'], 147, 'save: _meta conservato');
unlink($tmp);

$caught = false;
try {
    $bad = $cop;
    $bad['items'][0]['titolo'] = "byte non validi \xFF\xFE";
    bandi_store_save($tmp, $bad);
} catch (RuntimeException) {
    $caught = true;
}
t_true($caught, 'save: json_encode fallito solleva eccezione invece di scrivere');
t_true(!file_exists($tmp), 'save: nessun file scritto in caso di errore');
```

- [ ] **Step 2: Eseguire i test e verificare che falliscano**

```bash
php tests/run.php
```

Atteso: errore fatale su `lib/bandi_store.php` mancante.

- [ ] **Step 3: Implementare `lib/bandi_store.php`**

```php
<?php
declare(strict_types=1);

function bandi_store_empty(): array {
    return [
        '_meta' => [
            'last_run' => null,
            'fonti' => [],
            'copertura' => ['attesi_api' => 0, 'raccolti' => 0, 'per_regione' => []],
        ],
        'items' => [],
    ];
}

function bandi_store_load(string $path): array {
    if (!file_exists($path)) {
        return bandi_store_empty();
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
        return bandi_store_empty();
    }
    $vuoto = bandi_store_empty();
    $data['_meta'] ??= $vuoto['_meta'];
    $data['_meta']['fonti'] ??= [];
    $data['_meta']['copertura'] ??= $vuoto['_meta']['copertura'];
    return $data;
}

/**
 * Unisce le voci di una fonte a quelle già presenti. Non cancella mai: una fonte
 * che torna vuota o non risponde lascia intatto l'archivio. Non c'è potatura,
 * per scelta: i bandi chiusi sono il valore della pagina.
 */
function bandi_store_merge(array $store, string $fonteId, array $voci, string $nowIso): array {
    $byId = [];
    foreach ($store['items'] as $existing) {
        $byId[$existing['id']] = $existing;
    }
    foreach ($voci as $voce) {
        if (isset($byId[$voce['id']])) {
            // Si aggiorna ciò che la fonte può correggere in corsa - proroghe,
            // titoli, link al decreto - ma non la data di pubblicazione, che è
            // quella della prima rilevazione.
            foreach (['titolo', 'scopo', 'priorita', 'codice_intervento', 'scadenza',
                      'terminato_in_fonte', 'nota', 'url_ufficiale', 'regioni',
                      'dettagli_mancanti'] as $campo) {
                $byId[$voce['id']][$campo] = $voce[$campo];
            }
            continue;
        }
        $byId[$voce['id']] = $voce;
    }
    $store['items'] = array_values($byId);
    $store['_meta']['fonti'][$fonteId] = [
        'last_ok' => $nowIso,
        'last_error' => null,
        'consecutive_failures' => 0,
    ];
    return $store;
}

function bandi_store_mark_failure(array $store, string $fonteId, string $errore, string $nowIso): array {
    $prev = $store['_meta']['fonti'][$fonteId] ?? [
        'last_ok' => null, 'last_error' => null, 'consecutive_failures' => 0,
    ];
    $store['_meta']['fonti'][$fonteId] = [
        'last_ok' => $prev['last_ok'],
        'last_error' => $errore,
        'consecutive_failures' => ((int) $prev['consecutive_failures']) + 1,
    ];
    return $store;
}

/**
 * Registra lo scarto fra quanto l'API dichiara e quanto è stato davvero
 * raccolto. Senza questo confronto un cambio di paginazione farebbe perdere
 * bandi in silenzio.
 */
function bandi_store_set_copertura(array $store, int $attesiApi, array $perRegione): array {
    $store['_meta']['copertura'] = [
        'attesi_api' => $attesiApi,
        'raccolti' => count($store['items']),
        'per_regione' => $perRegione,
    ];
    return $store;
}

/** Soglia più larga di quella delle news: i bandi cambiano in settimane. */
function bandi_fonte_is_stale(array $store, string $fonteId, string $nowIso, int $giorni = 7): bool {
    $lastOk = $store['_meta']['fonti'][$fonteId]['last_ok'] ?? null;
    if ($lastOk === null) {
        return true;
    }
    return (strtotime($nowIso) - strtotime($lastOk)) > $giorni * 86400;
}

function bandi_store_save(string $path, array $store): void {
    $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('json_encode fallito: ' . json_last_error_msg());
    }
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException("Scrittura fallita: $path");
    }
}
```

- [ ] **Step 4: Eseguire i test e verificare che passino**

```bash
php tests/run.php
```

Atteso: `0 falliti`, exit code 0.

- [ ] **Step 5: Commit**

```bash
git add lib/bandi_store.php tests/test_bandi_store.php
git commit -m "feat: store dei bandi con merge non distruttivo e riconciliazione della copertura"
```

---

### Task 4: Dati curati — regioni, calendari ufficiali, FLAG e fonti

Task a sé perché è dato, non codice: si verifica leggendolo, non eseguendolo. I 18 calendari vengono dal PDF MASAF del 27/07/2026, i 29 FLAG dalla pagina *Bandi GALPA* dell'aggregatore, con i nomi letti dai rispettivi siti il 2026-07-30.

**Files:**
- Create: `data/bandi_regioni.json`, `data/bandi_fonti.json`

**Interfaces:**
- Produces: `data/bandi_regioni.json` con chiavi `regioni[].slug|nome|calendario_ufficiale|flag[]`; `data/bandi_fonti.json` con `aggregatore.base_url` e `feed[]`. Consumati da Task 5 e 6.

- [ ] **Step 1: Creare `data/bandi_regioni.json`**

Gli slug sono quelli delle categorie dell'aggregatore: devono corrispondere esatti, altrimenti le voci non trovano la loro sezione. Trentino-Alto Adige e Valle d'Aosta esistono come categoria ma non figurano nel PDF MASAF: `calendario_ufficiale` resta `null` e la pagina mostra la sezione senza link.

```json
{
  "_meta": {
    "fonte_calendari": "MASAF - Link_OI_Calendari_di_Avvisi_e_Bandi_FEAMPA-27-luglio-2026.pdf",
    "fonte_flag": "feampabandionline.it/pagine-gal-pesca-acquacoltura/",
    "verified_on": "2026-07-30"
  },
  "regioni": [
    {
      "slug": "abruzzo",
      "nome": "Abruzzo",
      "calendario_ufficiale": "https://pesca.regione.abruzzo.it/fondo-europeo-feampa-2021-2027/calendario-avvisi",
      "flag": [
        { "nome": "GAL Pesca Abruzzo", "url": "https://galpescaabruzzo.it/download-category/bandi/" }
      ]
    },
    {
      "slug": "basilicata",
      "nome": "Basilicata",
      "calendario_ufficiale": "https://feampa.regione.basilicata.it/",
      "flag": [
        { "nome": "GAL Pesca La Cittadella del Sapere", "url": "https://www.lacittadelladelsapere.it/wp/gal-pesca/" }
      ]
    },
    {
      "slug": "calabria",
      "nome": "Calabria",
      "calendario_ufficiale": "https://www.regione.calabria.it/dipartimento-agricoltura-risorse-agroalimentari-e-forestazione/aree-tematiche/feamp/",
      "flag": [
        { "nome": "FLAG Borghi Marinari dello Ionio", "url": "https://www.borghimarinaridelloionio.it/bandi-e-avvisi.html" },
        { "nome": "GALPA MariCal", "url": "https://www.galpamarical.it/bandi-e-avvisi/" }
      ]
    },
    {
      "slug": "campania",
      "nome": "Campania",
      "calendario_ufficiale": "https://agricoltura.regione.campania.it/FEAMPA/FEAMPA.html",
      "flag": [
        { "nome": "GAL Approdo di Ulisse", "url": "https://agricoltura.regione.campania.it/FEAMPA/FLAG/approdo_di_ulisse.html" },
        { "nome": "GAL Parthenope", "url": "https://agricoltura.regione.campania.it/FEAMPA/FLAG/parthenope.html" },
        { "nome": "GAL Magna Graecia", "url": "https://agricoltura.regione.campania.it/FEAMPA/FLAG/magna_graecia.html" }
      ]
    },
    {
      "slug": "emilia-romagna",
      "nome": "Emilia-Romagna",
      "calendario_ufficiale": "https://agricoltura.regione.emilia-romagna.it/feampa-2021-2027/approfondimenti/calendari-avvisi-pubblici",
      "flag": [
        { "nome": "GALPA Costa Emilia-Romagna", "url": "https://galpa.flag-costaemiliaromagna.it/bandi/" }
      ]
    },
    {
      "slug": "friuli-venezia-giulia",
      "nome": "Friuli-Venezia Giulia",
      "calendario_ufficiale": "https://europa.regione.fvg.it/it/programmi-36605/feampa-fondo-europeo-per-gli-affari-marittimi-per-la-pesca-e-lacquacoltura-39987/calendario-bandi-127417",
      "flag": [
        { "nome": "GALPA FVG", "url": "https://vg.camcom.it/fare-impresa/galpa-fvg" }
      ]
    },
    {
      "slug": "lazio",
      "nome": "Lazio",
      "calendario_ufficiale": "https://www.lazioeuropa.it/documenti/pn-feampa-calendario-opportunita-di-finanziamento/",
      "flag": [
        { "nome": "GAL Pesca Lazio", "url": "https://galpescalazio.it/" }
      ]
    },
    {
      "slug": "liguria",
      "nome": "Liguria",
      "calendario_ufficiale": "https://www.agriligurianet.it/it/impresa/sostegno-economico/contributi-per-la-pesca/feampa-2021-2027/approfondimenti.html",
      "flag": [
        { "nome": "GAL FISH Liguria", "url": "https://galfishliguria.it/bandi-e-avvisi/" }
      ]
    },
    {
      "slug": "lombardia",
      "nome": "Lombardia",
      "calendario_ufficiale": "https://ue.regione.lombardia.it/it/pc2127/po-feamp-2021-2027",
      "flag": []
    },
    {
      "slug": "marche",
      "nome": "Marche",
      "calendario_ufficiale": "https://www.regione.marche.it/Entra-in-Regione/Attivita-Ittiche/FEAMPA-2021-2027-Fondo-Europeo-per-gli-Affari-Marittimi-la-Pesca-e-lAcquacoltura/Presentazione",
      "flag": [
        { "nome": "CLLD GAL Pesca Marche", "url": "https://www.regione.marche.it/Entra-in-Regione/Attivita-Ittiche/FEAMPA-2021-2027-Fondo-Europeo-per-gli-Affari-Marittimi-la-Pesca-e-lAcquacoltura/CLLD-GAL-Pesca" }
      ]
    },
    {
      "slug": "molise",
      "nome": "Molise",
      "calendario_ufficiale": "https://www.regione.molise.it/flex/cm/pages/ServeBLOB.php/L/IT/IDPagina/21039",
      "flag": [
        { "nome": "GAL MARE Molise Costiero", "url": "https://www.flagmolise.it/avvisi-e-bandi/" }
      ]
    },
    {
      "slug": "piemonte",
      "nome": "Piemonte",
      "calendario_ufficiale": "https://www.regione.piemonte.it/web/temi/fondi-progetti-europei/fondo-europeo-affari-marittimi-pesca-acquacoltura-feamp-feampa/obiettivi-misure-bandi-feampa-2021-2027",
      "flag": []
    },
    {
      "slug": "puglia",
      "nome": "Puglia",
      "calendario_ufficiale": "https://regione.puglia.it/web/feampa-21-27",
      "flag": [
        { "nome": "GAL Pesca Gargano Mare", "url": "https://galpescagarganomare.it/bandi-e-avvisi/" },
        { "nome": "GAL Terre di Mare", "url": "https://galterredimare.it/" },
        { "nome": "GAL Terra dei Trulli e di Barsento", "url": "https://www.galtrulli-barsento.it/category/gare-e-avvisi/gare-e-avvisi-attivi/" },
        { "nome": "GAL Blu", "url": "https://www.galblu.it/" }
      ]
    },
    {
      "slug": "sardegna",
      "nome": "Sardegna",
      "calendario_ufficiale": "https://www.regione.sardegna.it/atti-bandi-archivi/atti-amministrativi/tutti-gli-atti/174118909192638",
      "flag": [
        { "nome": "FLAG Sardegna Sud Occidentale", "url": "https://www.flagsardegnasudoccidentale.it/bandi-avvisi" },
        { "nome": "FLAG Nord Sardegna", "url": "https://www.flagnordsardegna.it/" }
      ]
    },
    {
      "slug": "sicilia",
      "nome": "Sicilia",
      "calendario_ufficiale": "https://www.regione.sicilia.it/istituzioni/regione/strutture-regionali/assessorato-agricoltura-sviluppo-rurale-pesca-mediterranea/dipartimento-pesca-mediterranea/calendario-avvisi-bandi-feampa-2021-2027",
      "flag": [
        { "nome": "GAC Golfo di Patti", "url": "https://gacgolfodipatti.it/" },
        { "nome": "GALP dei Golfi", "url": "https://galpdeigolfi.it/categoria/avvisi-pubblici/" },
        { "nome": "GALP Golfo di Termini Imerese", "url": "https://www.galpgolfoditermini.it/category/bandi-e-avvisi/" },
        { "nome": "GAL Pesca Trapanese", "url": "https://www.galpescatrapanese.it/" },
        { "nome": "FLAG Il Sole e l'Azzurro", "url": "https://www.flagsoleazzurro.it/avvisi/" },
        { "nome": "FLAG Riviera Jonica Etnea", "url": "https://www.flagrivieraetnea.it/category/news/" },
        { "nome": "GAC dei Due Mari", "url": "https://www.gacdeiduemari.it/" }
      ]
    },
    {
      "slug": "toscana",
      "nome": "Toscana",
      "calendario_ufficiale": "https://www.regione.toscana.it/feampa-2021-2027/calendario-bandi-e-avvisi",
      "flag": [
        { "nome": "GALPA Toscana", "url": "https://www.galpatoscana.it/bandi-aperti-e-chiusi/" }
      ]
    },
    {
      "slug": "trentino-alto-adige",
      "nome": "Trentino-Alto Adige",
      "calendario_ufficiale": null,
      "flag": []
    },
    {
      "slug": "umbria",
      "nome": "Umbria",
      "calendario_ufficiale": "https://www.regione.umbria.it/agricoltura/fondo-europeo-affari-marittimi-pesca-e-acquacoltura-feampa-",
      "flag": []
    },
    {
      "slug": "valle-daosta",
      "nome": "Valle d'Aosta",
      "calendario_ufficiale": null,
      "flag": []
    },
    {
      "slug": "veneto",
      "nome": "Veneto",
      "calendario_ufficiale": "https://feampa.regione.veneto.it/bandi",
      "flag": [
        { "nome": "GALPA Chioggia e Delta del Po", "url": "https://galpachioggiadeltapo.it/" },
        { "nome": "FLAG Veneziano - VeGAL", "url": "https://www.vegal.net/bandi/" }
      ]
    }
  ]
}
```

- [ ] **Step 2: Creare `data/bandi_fonti.json`**

Le parole chiave sono le stesse di `data/news_sources.json`: due liste divergenti dello stesso concetto sarebbero un difetto. La Basilicata ha `keywords: []` perché il suo sito tratta solo FEAMPA e filtrare scarterebbe voci buone.

```json
{
  "aggregatore": {
    "id": "aggregatore",
    "label": "FEAMPA Bandi Online",
    "base_url": "https://www.feampabandionline.it",
    "max_pagine_per_regione": 10
  },
  "feed": [
    {
      "id": "basilicata-rss",
      "label": "Regione Basilicata · FEAMPA",
      "url": "https://feampa.regione.basilicata.it/feed/",
      "regione": "basilicata",
      "keywords": []
    },
    {
      "id": "calabria-rss",
      "label": "Regione Calabria",
      "url": "https://www.regione.calabria.it/feed/",
      "regione": "calabria",
      "keywords": ["pesca", "pescher", "ittic", "acquacolt", "mollusch", "vongol", "tonno", "FEAMPA", "GSA", "marittim"]
    },
    {
      "id": "lazio-rss",
      "label": "Lazio Europa",
      "url": "https://www.lazioeuropa.it/feed/",
      "regione": "lazio",
      "keywords": ["pesca", "pescher", "ittic", "acquacolt", "mollusch", "vongol", "tonno", "FEAMPA", "GSA", "marittim"]
    }
  ]
}
```

- [ ] **Step 3: Verificare che i dati siano coerenti**

```bash
cd "C:/Users/giang/Progetti Claude/masaf-decreti-pesca"
php -r '
$r = json_decode(file_get_contents("data/bandi_regioni.json"), true);
$f = json_decode(file_get_contents("data/bandi_fonti.json"), true);
if ($r === null || $f === null) { echo "JSON NON VALIDO\n"; exit(1); }
$regioni = $r["regioni"];
$flag = array_sum(array_map(fn($x) => count($x["flag"]), $regioni));
$cal = count(array_filter($regioni, fn($x) => $x["calendario_ufficiale"] !== null));
echo "regioni: " . count($regioni) . " | calendari: $cal | flag: $flag\n";
$slug = array_column($regioni, "slug");
echo "slug duplicati: " . (count($slug) - count(array_unique($slug))) . "\n";
foreach ($f["feed"] as $x) {
  echo "feed {$x["id"]} -> regione {$x["regione"]}: " .
       (in_array($x["regione"], $slug, true) ? "ok" : "SLUG INESISTENTE") . "\n";
}'
```

Atteso esatto: `regioni: 20 | calendari: 18 | flag: 29`, `slug duplicati: 0`, e i tre feed tutti `ok`.

- [ ] **Step 4: Verificare che gli slug corrispondano davvero alle categorie della fonte**

Il file temporaneo si scrive nella cartella del progetto, non in `/tmp`: in Git Bash su Windows quel percorso viene tradotto in una posizione che PHP non risolve, e il confronto fallirebbe con "file non trovato".

```bash
curl -s -A "Mozilla/5.0" --max-time 25 "https://www.feampabandionline.it/wp-json/wp/v2/categories?per_page=100" -o cat_tmp.json
php -r '
$cat = array_column(json_decode(file_get_contents("cat_tmp.json"), true), "slug");
$mie = array_column(json_decode(file_get_contents("data/bandi_regioni.json"), true)["regioni"], "slug");
$mancanti = array_diff($mie, $cat);
echo $mancanti ? "SLUG NON PRESENTI NELLA FONTE: " . implode(", ", $mancanti) . "\n" : "tutti gli slug esistono nella fonte\n";
$noti = ["terminato", "senza-categoria", "bandi-masaf-nazionali"];
echo "categorie della fonte non mappate: " . implode(", ", array_diff($cat, $mie, $noti)) . "\n";'
rm -f cat_tmp.json
```

Atteso: `tutti gli slug esistono nella fonte` e nessuna categoria non mappata oltre alle tre note. Se compare una regione nuova, aggiungerla a `bandi_regioni.json` prima di proseguire.

- [ ] **Step 5: Commit**

```bash
git add data/bandi_regioni.json data/bandi_fonti.json
git commit -m "feat: dati curati di regioni, calendari ufficiali e 29 FLAG"
```

---

### Task 5: Fetcher CLI

**Files:**
- Create: `bandi_fetcher.php`
- Test: verifica manuale a riga di comando (descritta negli step)

**Interfaces:**
- Consumes: `lib/bandi_normalize.php`, `lib/bandi_parser.php`, `lib/bandi_store.php`, `lib/news_parsers.php`, `data/bandi_fonti.json`, `data/bandi_regioni.json`
- Produces: `data/bandi.json` popolato; exit code `0` se almeno una regione è stata aggiornata, `1` se sono cadute tutte.

- [ ] **Step 1: Implementare `bandi_fetcher.php`**

```php
<?php
declare(strict_types=1);

/**
 * Scarica i bandi dall'aggregatore FEAMPA e dai feed istituzionali, li
 * normalizza e li unisce a data/bandi.json.
 *
 * Ogni regione e ogni feed sono isolati: una fonte che fallisce non impedisce
 * alle altre di aggiornarsi. L'archivio non viene mai potato.
 *
 * Uso: php bandi_fetcher.php
 */

require_once __DIR__ . '/lib/news_normalize.php';
require_once __DIR__ . '/lib/news_parsers.php';
require_once __DIR__ . '/lib/bandi_normalize.php';
require_once __DIR__ . '/lib/bandi_parser.php';
require_once __DIR__ . '/lib/bandi_store.php';

$dataDir     = __DIR__ . '/data';
$storeFile   = $dataDir . '/bandi.json';
$fontiFile   = $dataDir . '/bandi_fonti.json';
$regioniFile = $dataDir . '/bandi_regioni.json';
$logFile     = $dataDir . '/bandi.log';

function bandi_log(string $msg, string $logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

/** Come in scraper.php e news_fetcher.php: senza openssl i wrapper https:// non esistono. */
function bandi_fetch(string $url, bool $conHeader = false): string {
    $cmd = sprintf(
        'curl -s %s -L -A %s --max-time 25 %s',
        $conHeader ? '-i' : '',
        escapeshellarg('Mozilla/5.0 (Windows NT 10.0; Win64; x64) masaf-decreti-pesca-scraper/1.0'),
        escapeshellarg($url)
    );
    $body = shell_exec($cmd);
    if ($body === null || trim($body) === '') {
        throw new RuntimeException("download fallito (curl): $url");
    }
    return $body;
}

/**
 * X-WP-Total dice quanti post esistono in tutto: è il numero con cui si
 * riconcilia la raccolta. Sta solo negli header, quindi qui si scarica con -i.
 */
function bandi_totale_api(string $baseUrl): int {
    $risposta = bandi_fetch($baseUrl . '/wp-json/wp/v2/posts?per_page=1&_fields=id', true);
    if (preg_match('/^X-WP-Total:\s*(\d+)/mi', $risposta, $m) === 1) {
        return (int) $m[1];
    }
    throw new RuntimeException('header X-WP-Total assente');
}

$fonti = json_decode((string) @file_get_contents($fontiFile), true);
$regioniCfg = json_decode((string) @file_get_contents($regioniFile), true);
if (!is_array($fonti) || !isset($fonti['aggregatore']) || !is_array($regioniCfg)) {
    bandi_log('ERRORE: data/bandi_fonti.json o data/bandi_regioni.json mancante o non valido', $logFile);
    exit(1);
}

$now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('c');
$store = bandi_store_load($storeFile);

$agg = $fonti['aggregatore'];
$baseUrl = rtrim((string) $agg['base_url'], '/');
$maxPagine = (int) ($agg['max_pagine_per_regione'] ?? 10);

// --- censimento: quanti post dichiara la fonte, e come sono distribuiti ---
$attesi = 0;
$conteggi = [];
try {
    $attesi = bandi_totale_api($baseUrl);
    $conteggi = bandi_parse_categorie(bandi_fetch($baseUrl . '/wp-json/wp/v2/categories?per_page=100'));
    bandi_log("API: $attesi post dichiarati, " . count($conteggi) . ' categorie', $logFile);
} catch (Throwable $e) {
    bandi_log('API: ERRORE ' . $e->getMessage() . ' (si prosegue con le pagine archivio)', $logFile);
}

// --- archivio, una regione alla volta ---
$ok = 0;
$ko = 0;
$perRegione = [];
foreach ($regioniCfg['regioni'] as $regione) {
    $slug = (string) $regione['slug'];
    $raccolte = [];
    try {
        for ($pagina = 1; $pagina <= $maxPagine; $pagina++) {
            $url = $pagina === 1
                ? "$baseUrl/regione/$slug/"
                : "$baseUrl/regione/$slug/page/$pagina/";
            try {
                $html = bandi_fetch($url);
                $voci = bandi_parse_archivio($html, 'aggregatore');
            } catch (RuntimeException) {
                // Pagina oltre l'ultima: l'archivio è finito, non è un errore.
                break;
            }
            $raccolte = array_merge($raccolte, $voci);
            if (count($voci) < 10) {
                break;
            }
        }
        if ($raccolte === []) {
            throw new RuntimeException('nessuna voce raccolta');
        }
        $store = bandi_store_merge($store, "regione-$slug", $raccolte, $now);
        $perRegione[$slug] = count($raccolte);
        bandi_log("$slug: " . count($raccolte) . ' voci', $logFile);
        $ok++;
    } catch (Throwable $e) {
        $store = bandi_store_mark_failure($store, "regione-$slug", $e->getMessage(), $now);
        bandi_log("$slug: ERRORE " . $e->getMessage(), $logFile);
        $ko++;
    }
}

// --- categoria nazionale: non è una regione, ma sta nello stesso archivio ---
try {
    $voci = bandi_parse_archivio(bandi_fetch("$baseUrl/regione/bandi-masaf-nazionali/"), 'aggregatore');
    $store = bandi_store_merge($store, 'regione-bandi-masaf-nazionali', $voci, $now);
    $perRegione['bandi-masaf-nazionali'] = count($voci);
    bandi_log('bandi-masaf-nazionali: ' . count($voci) . ' voci', $logFile);
    $ok++;
} catch (Throwable $e) {
    $store = bandi_store_mark_failure($store, 'regione-bandi-masaf-nazionali', $e->getMessage(), $now);
    bandi_log('bandi-masaf-nazionali: ERRORE ' . $e->getMessage(), $logFile);
    $ko++;
}

// --- feed istituzionali ---
foreach ($fonti['feed'] as $feed) {
    $id = (string) $feed['id'];
    try {
        $items = news_parse_rss(bandi_fetch((string) $feed['url']), $id, $now);
        $voci = bandi_da_feed($items, (string) $feed['regione'], $feed['keywords'] ?? []);
        $store = bandi_store_merge($store, $id, $voci, $now);
        bandi_log("$id: " . count($voci) . ' segnalazioni in tema su ' . count($items) . ' voci', $logFile);
        $ok++;
    } catch (Throwable $e) {
        $store = bandi_store_mark_failure($store, $id, $e->getMessage(), $now);
        bandi_log("$id: ERRORE " . $e->getMessage(), $logFile);
        $ko++;
    }
}

$store['_meta']['last_run'] = $now;
$store = bandi_store_set_copertura($store, $attesi, $perRegione);

$daAggregatore = count(array_filter(
    $store['items'],
    static fn(array $v): bool => $v['origine'] === 'aggregatore'
));
if ($attesi > 0 && $daAggregatore < $attesi) {
    bandi_log("ATTENZIONE: raccolte $daAggregatore voci su $attesi dichiarate dall'API", $logFile);
}

try {
    bandi_store_save($storeFile, $store);
} catch (Throwable $e) {
    bandi_log('ERRORE salvataggio: ' . $e->getMessage(), $logFile);
    exit(1);
}

bandi_log("Fonti ok: $ok, fallite: $ko, voci totali: " . count($store['items']), $logFile);
exit($ok > 0 ? 0 : 1);
```

- [ ] **Step 2: Eseguire a freddo e verificare il risultato**

```bash
cd "C:/Users/giang/Progetti Claude/masaf-decreti-pesca"
php bandi_fetcher.php
echo "exit: $?"
```

Atteso: exit `0`; nel log una riga per regione; le regioni senza bandi (Trentino-Alto Adige, Valle d'Aosta) possono legittimamente dare `ERRORE nessuna voce raccolta`. Il totale delle voci deve avvicinarsi a 147.

- [ ] **Step 3: Verificare copertura, stati e accenti**

```bash
php -r '
$d = json_decode(file_get_contents("data/bandi.json"), true);
require_once "lib/bandi_normalize.php";
$oggi = date("Y-m-d");
$stati = ["aperto" => 0, "chiuso" => 0, "da_verificare" => 0];
foreach ($d["items"] as $v) { $stati[bandi_stato($v["scadenza"], $v["terminato_in_fonte"], $oggi)]++; }
echo "voci: " . count($d["items"]) . "\n";
echo "attesi API: " . $d["_meta"]["copertura"]["attesi_api"] . "\n";
print_r($stati);
$conAccento = 0;
foreach ($d["items"] as $v) { if (preg_match("/[àèéìòù]/u", $v["titolo"] . $v["scopo"])) { $conAccento++; } }
echo "voci con accenti resi correttamente: $conAccento\n";
$mancanti = count(array_filter($d["items"], fn($v) => $v["dettagli_mancanti"]));
echo "voci con dettagli mancanti: $mancanti\n";'
```

Atteso: circa 147 voci dall'aggregatore più le segnalazioni dei feed; una decina di `aperto`; `conAccento` maggiore di zero con accenti veri (`à`, `è`), mai `Ã ` o punti interrogativi; `dettagli mancanti` basso, sostanzialmente le sole voci da feed.

- [ ] **Step 4: Eseguire una seconda volta e verificare che non duplichi**

```bash
php bandi_fetcher.php > /dev/null
php -r '
$d = json_decode(file_get_contents("data/bandi.json"), true);
$ids = array_column($d["items"], "id");
echo "voci: " . count($ids) . " | id unici: " . count(array_unique($ids)) . "\n";'
```

Atteso: i due numeri coincidono.

- [ ] **Step 5: Verificare l'isolamento di una regione rotta**

Modificare temporaneamente lo slug di una regione in `data/bandi_regioni.json` (per esempio `toscana` → `toscana-inesistente`), poi:

```bash
php bandi_fetcher.php
echo "exit: $?"
```

Atteso: exit `0`, nel log `toscana-inesistente: ERRORE`, le altre regioni aggiornate e il numero totale di voci **non diminuito**. Poi **ripristinare** lo slug corretto e rieseguire.

- [ ] **Step 6: Commit**

```bash
git add bandi_fetcher.php data/bandi.json
git commit -m "feat: fetcher dei bandi con isolamento per regione e riconciliazione API"
```

---

### Task 6: Pagina `bandi.php`

**Files:**
- Create: `bandi.php`
- Modify: `assets/style.css` (stili in coda), `index.php` (topbar), `news.php` (topbar)

**Interfaces:**
- Consumes: `data/bandi.json`, `data/bandi_regioni.json`, `data/bandi_fonti.json`, `bandi_stato()` da `lib/bandi_normalize.php`, `bandi_fonte_is_stale()` e `bandi_store_load()` da `lib/bandi_store.php`

- [ ] **Step 1: Aggiungere gli stili in coda ad `assets/style.css`**

Riusano le variabili già definite nel `:root` del file (`--paper-raised`, `--line`, `--ink`, `--muted`, `--brass`, `--brass-deep`, `--warn`), quindi il tema scuro funziona senza altro lavoro.

```css
  /* --- bandi --- */
  .bandi-aperti { border: 1px solid rgb(var(--brass)); border-radius: 8px; padding: 1.2rem 1.3rem;
    margin-bottom: 2.2rem; background: rgb(var(--brass) / 0.06); }
  .bandi-aperti h2 { font-family: Georgia, serif; font-size: 1.25rem; margin: 0 0 0.9rem; }
  .bandi-row { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.6rem;
    padding: 0.55rem 0; border-top: 1px solid rgb(var(--line)); }
  .bandi-row:first-of-type { border-top: none; }
  .bandi-row .scad { font-family: ui-monospace, monospace; font-variant-numeric: tabular-nums;
    font-size: 0.76rem; color: rgb(var(--brass-deep)); white-space: nowrap; }
  .bandi-row .reg { font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.06em;
    color: rgb(var(--muted)); }
  .bandi-row a { color: rgb(var(--ink)); text-decoration: none; font-weight: 600; }
  .bandi-row a:hover { text-decoration: underline; }
  .bandi-sec { margin-bottom: 2.6rem; scroll-margin-top: 4.5rem; }
  .bandi-sec-head { display: flex; align-items: baseline; flex-wrap: wrap; gap: 0.75rem;
    border-left: 3px solid rgb(var(--brass)); padding-left: 0.9rem; margin-bottom: 0.5rem; }
  .bandi-sec-head h2 { font-family: Georgia, serif; font-weight: 600; font-size: 1.35rem; margin: 0; }
  .bandi-sec-head .count { font-family: ui-monospace, monospace; font-size: 0.75rem; color: rgb(var(--muted)); }
  .bandi-links { display: flex; flex-wrap: wrap; gap: 0.5rem 1.1rem; margin: 0 0 1rem 0.9rem;
    font-size: 0.8rem; }
  .bandi-links .lbl { color: rgb(var(--muted)); }
  .bandi-card { display: flex; flex-direction: column; gap: 0.45rem; background: rgb(var(--paper-raised));
    border: 1px solid rgb(var(--line)); border-left: 3px solid rgb(var(--st-c)); border-radius: 6px;
    padding: 0.9rem 1.1rem; margin-bottom: 0.7rem; }
  .bandi-card .head { display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem; }
  .bandi-badge { font-family: ui-monospace, monospace; font-size: 0.68rem; letter-spacing: 0.06em;
    text-transform: uppercase; color: rgb(var(--st-c)); border: 1px solid rgb(var(--st-c));
    border-radius: 999px; padding: 0.1rem 0.5rem; }
  .bandi-card .cod { font-family: ui-monospace, monospace; font-size: 0.72rem; color: rgb(var(--muted)); }
  .bandi-card .date { font-family: ui-monospace, monospace; font-variant-numeric: tabular-nums;
    font-size: 0.72rem; color: rgb(var(--muted)); margin-left: auto; }
  .bandi-card h3 { font-family: Georgia, serif; font-weight: 600; font-size: 1.02rem; line-height: 1.3; margin: 0; }
  .bandi-card h3 a { color: rgb(var(--ink)); text-decoration: none; }
  .bandi-card h3 a:hover { text-decoration: underline; }
  .bandi-card p { font-size: 0.86rem; color: rgb(var(--muted)); margin: 0; line-height: 1.5; }
  .bandi-card .prio { font-size: 0.76rem; color: rgb(var(--muted)); font-style: italic; }
  .bandi-card .nota { font-size: 0.8rem; color: rgb(var(--warn)); }
  .bandi-card .foot { display: flex; flex-wrap: wrap; gap: 1rem; font-size: 0.78rem; padding-top: 0.25rem; }
  .bandi-avviso { border: 1px dashed rgb(var(--warn)); border-radius: 8px; padding: 0.9rem 1.1rem;
    margin-bottom: 1.6rem; font-size: 0.86rem; color: rgb(var(--warn)); }
  .bandi-empty { border: 1px solid rgb(var(--line)); border-radius: 8px; padding: 2rem 1.2rem;
    text-align: center; color: rgb(var(--muted)); }
  .bandi-disclaimer { border-left: 3px solid rgb(var(--line)); padding: 0.3rem 0 0.3rem 1rem;
    margin-bottom: 2rem; font-size: 0.85rem; color: rgb(var(--muted)); max-width: 72ch; }
```

- [ ] **Step 2: Scrivere `bandi.php`**

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bandi_normalize.php';
require_once __DIR__ . '/lib/bandi_store.php';

$dataDir = __DIR__ . '/data';
$store   = bandi_store_load($dataDir . '/bandi.json');
$regCfg  = json_decode((string) @file_get_contents($dataDir . '/bandi_regioni.json'), true) ?? ['regioni' => []];
$fonti   = json_decode((string) @file_get_contents($dataDir . '/bandi_fonti.json'), true) ?? ['feed' => []];

$now  = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('c');
$oggi = substr($now, 0, 10);

function h(int|string|null $s): string {
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function data_it(?string $iso): string {
    return $iso === null || $iso === '' ? '—' : date('d/m/Y', strtotime($iso));
}

// Lo stato non è salvato nel JSON: si calcola qui, perché dipende da oggi.
// Un bando aperto diventa chiuso al passare della scadenza anche senza fetch.
$items = $store['items'];
foreach ($items as $i => $v) {
    $items[$i]['stato'] = bandi_stato($v['scadenza'], $v['terminato_in_fonte'], $oggi);
}

$coloriStato = [
    'aperto' => '62 115 104',
    'chiuso' => '107 100 89',
    'da_verificare' => '156 122 46',
];

// Indice per regione: una voce con più regioni compare in ognuna.
$perRegione = [];
foreach ($items as $v) {
    foreach ($v['regioni'] as $slug) {
        $perRegione[$slug][] = $v;
    }
    if ($v['regioni'] === []) {
        $perRegione['_non_attribuiti'][] = $v;
    }
}

$aperti = array_values(array_filter($items, static fn(array $v): bool => $v['stato'] === 'aperto'));
usort($aperti, static fn(array $a, array $b): int => strcmp((string) $a['scadenza'], (string) $b['scadenza']));

$nomiRegione = [];
foreach ($regCfg['regioni'] as $r) {
    $nomiRegione[$r['slug']] = $r['nome'];
}

// Sezioni: prima i bandi nazionali, poi le regioni con almeno una voce, infine i non attribuiti.
$sezioni = [];
if (!empty($perRegione['bandi-masaf-nazionali'])) {
    $sezioni[] = ['slug' => 'bandi-masaf-nazionali', 'nome' => 'Bandi MASAF nazionali',
                  'cfg' => null, 'voci' => $perRegione['bandi-masaf-nazionali']];
}
foreach ($regCfg['regioni'] as $r) {
    $voci = $perRegione[$r['slug']] ?? [];
    if ($voci === []) {
        continue;
    }
    $sezioni[] = ['slug' => $r['slug'], 'nome' => $r['nome'], 'cfg' => $r, 'voci' => $voci];
}
if (!empty($perRegione['_non_attribuiti'])) {
    $sezioni[] = ['slug' => '_non_attribuiti', 'nome' => 'Non attribuiti',
                  'cfg' => null, 'voci' => $perRegione['_non_attribuiti']];
}
foreach ($sezioni as $i => $s) {
    usort($sezioni[$i]['voci'], static fn(array $a, array $b): int
        => strcmp((string) $b['pubblicazione'], (string) $a['pubblicazione']));
}

// Salute: si controllano l'aggregatore (una voce per regione) e i feed dichiarati.
// Gli id interni ("regione-toscana", "basilicata-rss") non sono leggibili in
// pagina: si traducono nel nome della regione o nell'etichetta del feed.
$etichette = [];
foreach ($nomiRegione as $slug => $nome) {
    $etichette["regione-$slug"] = $nome;
}
$etichette['regione-bandi-masaf-nazionali'] = 'Bandi MASAF nazionali';
foreach ($fonti['feed'] ?? [] as $f) {
    $etichette[$f['id']] = $f['label'];
}

$ferme = [];
foreach (array_keys($store['_meta']['fonti']) as $id) {
    if (bandi_fonte_is_stale($store, (string) $id, $now)) {
        $ferme[] = $etichette[$id] ?? (string) $id;
    }
}
$copertura = $store['_meta']['copertura'] ?? ['attesi_api' => 0, 'raccolti' => 0];
$daAggregatore = count(array_filter($items, static fn(array $v): bool => $v['origine'] === 'aggregatore'));
$scarto = ((int) $copertura['attesi_api']) - $daAggregatore;

$lastRun = $store['_meta']['last_run'] ?? null;
$lastRunLabel = $lastRun ? date('d/m/Y H:i', strtotime($lastRun)) : 'mai eseguito';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>Bandi pesca per regione</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <a href="index.php">← Registro decreti</a>
    <a href="news.php">News dal mondo della pesca</a>
  </div>

  <div class="masthead">
    <p class="eyebrow">Finanziamenti · FEAMPA 2021-2027 · aggiornamento automatico</p>
    <h1>Bandi per la pesca, regione per regione</h1>
    <p class="lede">Avvisi e bandi che riguardano pesca e acquacoltura, raccolti automaticamente e
      ordinati per territorio. I bandi in scadenza sono in evidenza; l'archivio dei bandi chiusi
      resta consultabile per intero.</p>
    <div class="meta-row">
      <span>Ultimo aggiornamento: <strong><?= h($lastRunLabel) ?></strong></span>
      <span>Bandi: <strong><?= count($items) ?></strong></span>
      <span>Aperti: <strong><?= count($aperti) ?></strong></span>
      <span>Regioni con bandi: <strong><?= count($sezioni) ?></strong></span>
    </div>
  </div>

  <div class="bandi-disclaimer">
    La fonte principale di questa pagina è <strong>FEAMPA Bandi Online</strong>, un aggregatore
    <strong>privato</strong> (Consorzio Mediterraneo con Legacoop Agroalimentare), non un canale
    istituzionale: in caso di divergenza fa fede il sito della Regione, raggiungibile dal link in
    testa a ogni sezione. Le voci contrassegnate <em>segnalazione</em> arrivano dai canali
    istituzionali regionali e non hanno una scadenza verificata.
  </div>

  <?php if ($ferme): ?>
  <div class="bandi-avviso">
    ⚠ Nessun aggiornamento da oltre 7 giorni per: <strong><?= h(implode(', ', $ferme)) ?></strong>.
    I bandi già raccolti restano consultabili.
  </div>
  <?php endif; ?>

  <?php if ($scarto > 0): ?>
  <div class="bandi-avviso">
    ⚠ Copertura incompleta: la fonte dichiara <strong><?= h((string) $copertura['attesi_api']) ?></strong>
    bandi, ne sono stati raccolti <strong><?= h((string) $daAggregatore) ?></strong>.
    Verificare sui siti regionali.
  </div>
  <?php endif; ?>

  <?php if (!$items): ?>
  <div class="bandi-empty">
    Nessun bando ancora raccolto. Esegui <code>php bandi_fetcher.php</code> per popolare la pagina.
  </div>
  <?php else: ?>

  <?php if ($aperti): ?>
  <div class="bandi-aperti">
    <h2>In scadenza · <?= count($aperti) ?> bandi aperti</h2>
    <?php foreach ($aperti as $v): ?>
    <div class="bandi-row">
      <span class="scad">scade <?= h(data_it($v['scadenza'])) ?></span>
      <span class="reg"><?= h(implode(', ', array_map(
          static fn(string $s): string => $nomiRegione[$s] ?? $s, $v['regioni']))) ?></span>
      <a href="<?= h($v['url_fonte']) ?>" target="_blank" rel="noopener"><?= h($v['titolo']) ?></a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="year-filter" role="group" aria-label="Filtra per stato">
    <span class="label">Mostra</span>
    <button type="button" class="yr-chip" data-stato="all" aria-pressed="true">Tutti</button>
    <button type="button" class="yr-chip" data-stato="aperto" aria-pressed="false">Solo aperti</button>
  </div>

  <div class="index">
    <?php foreach ($sezioni as $s): ?>
    <a class="chip" href="#sez-<?= h($s['slug']) ?>"><?= h($s['nome']) ?> <?= count($s['voci']) ?></a>
    <?php endforeach; ?>
  </div>

  <?php foreach ($sezioni as $s): ?>
  <section class="bandi-sec" id="sez-<?= h($s['slug']) ?>">
    <div class="bandi-sec-head">
      <h2><?= h($s['nome']) ?></h2>
      <span class="count"><?= count($s['voci']) ?> bandi</span>
    </div>

    <?php if ($s['cfg'] !== null): ?>
    <div class="bandi-links">
      <?php if (!empty($s['cfg']['calendario_ufficiale'])): ?>
      <span><span class="lbl">Calendario ufficiale:</span>
        <a href="<?= h($s['cfg']['calendario_ufficiale']) ?>" target="_blank" rel="noopener">sito della Regione ↗</a></span>
      <?php endif; ?>
      <?php if (!empty($s['cfg']['flag'])): ?>
      <span><span class="lbl">FLAG del territorio:</span>
        <?php foreach ($s['cfg']['flag'] as $k => $f): ?><?= $k > 0 ? ' · ' : '' ?><a href="<?= h($f['url']) ?>" target="_blank" rel="noopener"><?= h($f['nome']) ?></a><?php endforeach; ?>
      </span>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php foreach ($s['voci'] as $v): ?>
    <article class="bandi-card" data-stato="<?= h($v['stato']) ?>"
             style="--st-c: <?= h($coloriStato[$v['stato']]) ?>">
      <div class="head">
        <span class="bandi-badge"><?= h($v['stato'] === 'da_verificare' ? 'segnalazione' : $v['stato']) ?></span>
        <?php if ($v['codice_intervento'] !== ''): ?>
        <span class="cod">cod. <?= h($v['codice_intervento']) ?></span>
        <?php endif; ?>
        <span class="date">
          <?php if ($v['scadenza'] !== null): ?>scade <?= h(data_it($v['scadenza'])) ?>
          <?php else: ?>pubbl. <?= h(data_it($v['pubblicazione'])) ?><?php endif; ?>
        </span>
      </div>
      <h3><a href="<?= h($v['url_fonte']) ?>" target="_blank" rel="noopener"><?= h($v['titolo']) ?></a></h3>
      <?php if ($v['priorita'] !== '' && $v['priorita'] !== $v['titolo']): ?>
      <p class="prio"><?= h($v['priorita']) ?></p>
      <?php endif; ?>
      <?php if ($v['scopo'] !== '' && $v['scopo'] !== $v['titolo']): ?>
      <p><?= h($v['scopo']) ?></p>
      <?php endif; ?>
      <?php if ($v['nota'] !== ''): ?>
      <p class="nota"><?= h($v['nota']) ?></p>
      <?php endif; ?>
      <div class="foot">
        <?php if ($v['url_ufficiale'] !== null): ?>
        <a href="<?= h($v['url_ufficiale']) ?>" target="_blank" rel="noopener">Atto ufficiale ↗</a>
        <?php endif; ?>
        <?php if ($v['origine'] === 'istituzionale'): ?>
        <span class="prio">segnalazione dal canale istituzionale regionale</span>
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </section>
  <?php endforeach; ?>

  <?php endif; ?>

  <footer class="note">
    Pagina generata da <code>data/bandi.json</code>, aggiornato da <code>bandi_fetcher.php</code>
    una volta al giorno via Task Scheduler. L'archivio dei bandi chiusi non viene mai potato.
    Titoli e descrizioni appartengono alle fonti indicate: per il testo integrale seguire i
    collegamenti.
  </footer>

</div>

<script>
(function () {
  var buttons = document.querySelectorAll(".yr-chip[data-stato]");
  var cards = document.querySelectorAll(".bandi-card");
  buttons.forEach(function (btn) {
    btn.addEventListener("click", function () {
      buttons.forEach(function (b) { b.setAttribute("aria-pressed", "false"); });
      btn.setAttribute("aria-pressed", "true");
      var stato = btn.getAttribute("data-stato");
      cards.forEach(function (el) {
        el.style.display = (stato === "all" || el.getAttribute("data-stato") === stato) ? "" : "none";
      });
      document.querySelectorAll(".bandi-sec").forEach(function (sec) {
        var visibili = sec.querySelectorAll('.bandi-card:not([style*="display: none"])').length;
        sec.style.display = visibili === 0 ? "none" : "";
      });
    });
  });
})();
</script>
</body>
</html>
```

- [ ] **Step 3: Aggiungere il link nelle altre due pagine**

In `index.php`, nella `<div class="topbar">` già presente, aggiungere in coda:

```html
    <a href="bandi.php">Bandi per regione →</a>
```

In `news.php`, nella `<div class="topbar">`, aggiungere in coda lo stesso link. Le due topbar devono restare identiche per composizione: sono la navigazione del sito.

- [ ] **Step 4: Verificare la pagina**

```bash
cd "C:/Users/giang/Progetti Claude/masaf-decreti-pesca"
curl -s -o /dev/null -w "bandi: %{http_code}\n" http://localhost:8000/bandi.php
curl -s http://localhost:8000/bandi.php | grep -c "bandi-card"
curl -s http://localhost:8000/bandi.php | grep -o "Bandi: <strong>[0-9]*"
curl -s http://localhost:8000/bandi.php | grep -o "Aperti: <strong>[0-9]*"
```

Atteso: `200`; il numero di `bandi-card` pari alle voci (le voci multi-regione compaiono più volte, quindi può superare il contatore); i contatori coerenti con `data/bandi.json`. Aprire nel browser e verificare: riquadro dei bandi aperti ordinato per scadenza, chip indice funzionanti, link al calendario regionale e ai FLAG in testa alle sezioni, accenti corretti, tema scuro coerente.

- [ ] **Step 5: Verificare il caso "prima installazione"**

```bash
mv data/bandi.json data/bandi.json.tmp
curl -s -o /dev/null -w "senza dati: %{http_code}\n" http://localhost:8000/bandi.php
curl -s http://localhost:8000/bandi.php | grep -c "bandi-empty"
mv data/bandi.json.tmp data/bandi.json
```

Atteso: `200` e il riquadro "Nessun bando ancora raccolto" — mai un errore PHP.

- [ ] **Step 6: Verificare che le altre due pagine non siano regredite**

```bash
curl -s http://localhost:8000/ | grep -o "Voci catalogate: <strong>[0-9]*"
curl -s -o /dev/null -w "news: %{http_code}\n" http://localhost:8000/news.php
```

Atteso: `40` come prima delle modifiche, e `200` sulla pagina news.

- [ ] **Step 7: Commit**

```bash
git add bandi.php assets/style.css index.php news.php
git commit -m "feat: pagina dei bandi per regione con evidenza degli aperti"
```

---

### Task 7: Schedulazione giornaliera e verifica finale

**Files:**
- Modify: `.gitignore`

- [ ] **Step 1: Creare il task pianificato**

Task separato da quello dei decreti e da quello delle news: un guasto qui non deve sporcare il `LastTaskResult` del controllo decreti, che resta la funzione critica del progetto.

```powershell
$php = "C:\Users\giang\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
$action = New-ScheduledTaskAction -Execute $php -Argument '"C:\Users\giang\Progetti Claude\masaf-decreti-pesca\bandi_fetcher.php"'
$trigger = New-ScheduledTaskTrigger -Daily -At 6am
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable
Register-ScheduledTask -TaskName 'MASAF Bandi Pesca' -Action $action -Trigger $trigger -Settings $settings
```

`-AllowStartIfOnBatteries` non è opzionale: senza, il task viene rifiutato con `0x800710E0` quando il portatile non è alimentato, guasto già osservato su questo sistema.

- [ ] **Step 2: Verificare che il task giri davvero**

```powershell
Start-ScheduledTask -TaskName 'MASAF Bandi Pesca'
Start-Sleep -Seconds 90
Get-ScheduledTaskInfo -TaskName 'MASAF Bandi Pesca' | Format-List LastRunTime, LastTaskResult, NextRunTime
Get-Content "C:\Users\giang\Progetti Claude\masaf-decreti-pesca\data\bandi.log" -Tail 8
```

Atteso: `LastTaskResult: 0` e righe di log con l'orario dell'esecuzione appena lanciata. L'attesa è di 90 secondi perché il fetcher fa una ventina di richieste HTTP.

- [ ] **Step 3: Eseguire l'intera suite di test**

```bash
cd "C:/Users/giang/Progetti Claude/masaf-decreti-pesca"
php tests/run.php
echo "exit: $?"
```

Atteso: `0 falliti`, exit `0`. La suite comprende i test delle news e quelli dei bandi.

- [ ] **Step 4: Verificare che tutte le fonti irraggiungibili non distruggano l'archivio**

```bash
php -r '$d = json_decode(file_get_contents("data/bandi.json"), true); echo count($d["items"]), "\n";'
```

Annotare il numero. Poi, con la rete disattivata (o cambiando temporaneamente `base_url` in `data/bandi_fonti.json` in `https://host-inesistente.invalid`):

```bash
php bandi_fetcher.php
echo "exit: $?"
php -r '$d = json_decode(file_get_contents("data/bandi.json"), true); echo count($d["items"]), "\n";'
```

Atteso: exit `1`, numero di voci **identico** a prima. Poi ripristinare `base_url` e rieseguire il fetcher.

- [ ] **Step 5: Aggiornare `.gitignore` e committare**

```bash
printf 'server.log\ndata/scraper.log\ndata/news.log\ndata/bandi.log\n' > .gitignore
git add .gitignore
git commit -m "chore: esclude il log del fetcher bandi dal versionamento"
```

---

## Copertura dello spec

| Requisito dello spec | Task |
|---|---|
| Fetcher e pagina come unità isolate via `bandi.json` | 5, 6 |
| Fonti come dato in `bandi_fonti.json` | 4 |
| Aggregatore: censimento via API + campi dalle pagine archivio | 2, 5 |
| Feed istituzionali (Basilicata, Calabria, Lazio) con filtro keyword | 2, 4, 5 |
| Directory: 18 calendari ufficiali + 29 FLAG per regione | 4, 6 |
| Titolo dallo *Scopo contributo*, priorità come etichetta | 1, 6 |
| Stato calcolato, non copiato dalla categoria | 1, 6 |
| `regioni` come array; sezione nazionale a sé; *Non attribuiti* | 1, 6 |
| Segnalazioni senza scadenza distinte dalle schede | 2, 6 |
| Deduplica su URL normalizzato (SHA-1) | 2 |
| Best-effort a due livelli (`dettagli_mancanti`) | 2 |
| Isolamento per regione e per fonte | 5 |
| Merge non distruttivo, nessuna potatura | 3 |
| Riconciliazione della copertura con `X-WP-Total` | 3, 5, 6 |
| Salute per fonte e avviso oltre 7 giorni | 3, 6 |
| Exit code 0/1 leggibile dal Task Scheduler | 5 |
| Guardia su `iconv` e `json_encode` | 1, 3 |
| Dichiarazione in pagina: fonte privata, segnalazioni non verificate | 6 |
| Le 11 verifiche elencate nello spec | 1-3 (1-6), 5 (2-5), 6 (4-6), 7 (2-4) |

**Due scostamenti consapevoli dallo spec.**

1. **`stato` non è un campo di `bandi.json`.** Lo spec lo elenca fra i campi salvati, ma dipende da *oggi*: salvarlo significa avere un valore stantio ogni giorno in cui il fetcher non gira, proprio sul dato che la pagina mette in evidenza. Si salvano `scadenza` e `terminato_in_fonte`, e `bandi.php` calcola lo stato al rendering con la stessa `bandi_stato()` testata in Task 1. L'intento del requisito — stato calcolato, mai copiato dalla fonte — è rispettato più strettamente così.

2. **Nessuna deduplica fra origini diverse.** Un bando può comparire sia dall'aggregatore sia dal feed istituzionale della sua regione, con URL diversi e quindi id diversi. Riconoscerli come lo stesso bando richiederebbe un confronto fuzzy sui titoli, con il rischio di fondere bandi distinti della stessa priorità. La pagina li distingue con il badge di origine e la dicitura *segnalazione*; il limite è dichiarato in pagina nel riquadro introduttivo.
