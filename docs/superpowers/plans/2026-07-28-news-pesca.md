# Sezione news pesca — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aggiungere al sito una pagina `news.php` con le notizie sul mondo della pesca in Italia, alimentata automaticamente da due feed RSS di settore e dalle pagine notizie del MASAF.

**Architecture:** Due unità isolate che comunicano solo tramite `data/news.json`. `news_fetcher.php` (CLI, eseguito dal Task Scheduler) scarica, normalizza e salva; `news.php` legge quel file e disegna, senza alcun accesso di rete. La logica riutilizzabile sta in tre librerie sotto `lib/`, ciascuna con una responsabilità: normalizzazione, parsing, persistenza.

**Tech Stack:** PHP 8.3 (nessun framework, nessuna dipendenza installata), `curl.exe` di sistema per il fetch, SimpleXML per gli RSS, DOMDocument + XPath per l'HTML MASAF, `iconv` per le conversioni di charset.

## Global Constraints

- PHP 8.3 senza `mbstring`, `openssl`, estensione `curl`, `intl`. Disponibili: `iconv`, `simplexml`, `dom`, `libxml`, `json`.
- Nessuna libreria esterna, nessun composer, nessun bundler: il progetto non ha né deve acquisire dipendenze installabili.
- Il fetch HTTPS passa **sempre** da `shell_exec` su `curl.exe`, mai da `file_get_contents`: senza `openssl` i wrapper `https://` non esistono. Riferimento: `scraper.php:36-47`.
- Timeout 25 secondi per richiesta; User-Agent `Mozilla/5.0 (Windows NT 10.0; Win64; x64) masaf-decreti-pesca-scraper/1.0`.
- Ogni stringa proveniente dalla rete passa da `news_to_utf8()` prima di finire in un array destinato a `json_encode`.
- Tutti i file JSON si scrivono con `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`, come fa già `scraper.php:95`.
- Testo dell'interfaccia in italiano, con accenti corretti (`è`, `à`, `°`).
- Non si modifica `scraper.php`, né la logica di `index.php` che legge `catalog.json`/`known.json`.

---

## File Structure

| File | Responsabilità |
|---|---|
| `lib/news_normalize.php` | Funzioni pure: URL, id, charset, estratti, date. Nessun I/O. |
| `lib/news_parsers.php` | Da testo grezzo (XML o HTML) a voci normalizzate. Nessun I/O di rete. |
| `lib/news_store.php` | Caricamento, merge, potatura e salvataggio di `data/news.json`. |
| `news_fetcher.php` | Orchestratore CLI: legge le fonti, scarica, richiama i parser, salva. |
| `data/news_sources.json` | Configurazione delle fonti (dato, non codice). |
| `data/news.json` | Stato: voci + salute per fonte. Generato. |
| `assets/style.css` | CSS condiviso, estratto da `index.php`. |
| `news.php` | Rendering della pagina news. Sola lettura. |
| `index.php` | Modificato: `<style>` → `<link>`, più il link alla pagina news. |
| `tests/run.php` | Runner di test minimale, senza dipendenze. |
| `tests/test_*.php` | Test per libreria. |
| `tests/fixtures/` | Campioni RSS e HTML reali salvati su disco. |

---

### Task 1: Repository, runner di test e normalizzazione

Il progetto non è sotto controllo di versione: senza `git init` nessuno step di commit del piano è eseguibile, quindi si parte da lì. Nella stessa task entra il runner di test, perché è l'infrastruttura che serve alla prima funzione testabile.

**Files:**
- Create: `.gitignore`, `tests/run.php`, `lib/news_normalize.php`, `tests/test_normalize.php`

**Interfaces:**
- Consumes: niente
- Produces:
  - `news_normalize_url(string $url): string`
  - `news_item_id(string $url): string` — SHA-1 dell'URL normalizzato
  - `news_to_utf8(string $s, string $from = 'UTF-8'): string`
  - `news_clean_summary(string $html, int $max = 200): string`
  - `news_parse_date(string $raw, string $fallbackIso): string` — ISO 8601
  - Helper di test: `t_eq($actual, $expected, string $msg): void`, `t_true($cond, string $msg): void`

- [ ] **Step 1: Inizializzare il repository**

```bash
cd "C:/Users/giang/Progetti Claude/masaf-decreti-pesca"
git init
printf 'server.log\ndata/scraper.log\n' > .gitignore
git add -A
git commit -m "chore: versiona lo stato attuale del registro decreti"
```

- [ ] **Step 2: Scrivere il runner di test**

`tests/run.php`:

```php
<?php
declare(strict_types=1);

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = 0;

function t_eq($actual, $expected, string $msg): void {
    if ($actual === $expected) {
        $GLOBALS['t_pass']++;
        return;
    }
    $GLOBALS['t_fail']++;
    echo "FAIL: $msg\n  atteso:  " . var_export($expected, true)
       . "\n  ottenuto: " . var_export($actual, true) . "\n";
}

function t_true($cond, string $msg): void {
    t_eq((bool) $cond, true, $msg);
}

foreach (glob(__DIR__ . '/test_*.php') as $file) {
    require $file;
}

echo "\n{$GLOBALS['t_pass']} passati, {$GLOBALS['t_fail']} falliti\n";
exit($GLOBALS['t_fail'] > 0 ? 1 : 0);
```

- [ ] **Step 3: Scrivere i test che falliscono**

`tests/test_normalize.php`:

```php
<?php
require_once __DIR__ . '/../lib/news_normalize.php';

// --- news_normalize_url ---
t_eq(news_normalize_url('  https://x.it/a  '), 'https://x.it/a', 'spazi rimossi');
t_eq(news_normalize_url('https://x.it/a#sec'), 'https://x.it/a', 'fragment rimosso');
t_eq(news_normalize_url('https://x.it/a?utm_source=fb&id=3'), 'https://x.it/a?id=3', 'utm_ scartati');
t_eq(news_normalize_url('https://x.it/a?utm_source=fb'), 'https://x.it/a', 'query vuota niente ?');
t_eq(news_normalize_url('https://x.it/a?id=3'), 'https://x.it/a?id=3', 'query utile conservata');

// --- news_item_id ---
t_eq(
    news_item_id('https://x.it/a?utm_source=fb'),
    news_item_id('https://x.it/a'),
    'stesso articolo, stesso id nonostante utm'
);
t_true(news_item_id('https://x.it/a') !== news_item_id('https://x.it/b'), 'url diversi, id diversi');
t_eq(strlen(news_item_id('https://x.it/a')), 40, 'id sha1 lungo 40');

// --- news_to_utf8 ---
t_eq(news_to_utf8("attivit\xE0 di pesca", 'ISO-8859-1'), 'attività di pesca', 'latin1 convertito');
t_eq(news_to_utf8('attività di pesca'), 'attività di pesca', 'utf8 invariato');
t_true(json_encode(['t' => news_to_utf8("rotto \xFF\xFE qui")]) !== false, 'byte invalidi resi codificabili');

// --- news_clean_summary ---
t_eq(news_clean_summary('<p>Ciao   <b>mondo</b></p>'), 'Ciao mondo', 'tag via, spazi compattati');
t_eq(news_clean_summary('Pesca &amp; acquacoltura'), 'Pesca & acquacoltura', 'entita decodificate');
$long = str_repeat('parola ', 60);
$cut = news_clean_summary($long, 50);
t_true(strlen($cut) <= 54, 'troncato entro il limite');
t_true(str_ends_with($cut, '…'), 'ellissi finale');
t_eq(news_clean_summary('Attività à è ò', 200), 'Attività à è ò', 'accenti integri');

// --- news_parse_date ---
$fb = '2026-01-01T00:00:00+01:00';
t_eq(substr(news_parse_date('Tue, 28 Jul 2026 06:08:09 +0000', $fb), 0, 10), '2026-07-28', 'RFC-2822');
t_eq(substr(news_parse_date('28/07/2026', $fb), 0, 10), '2026-07-28', 'formato MASAF dd/mm/yyyy');
t_eq(news_parse_date('', $fb), $fb, 'stringa vuota usa il fallback');
t_eq(news_parse_date('non una data', $fb), $fb, 'spazzatura usa il fallback');
```

- [ ] **Step 4: Eseguire i test e verificare che falliscano**

```bash
php tests/run.php
```

Atteso: errore fatale `Failed opening required '.../lib/news_normalize.php'`.

- [ ] **Step 5: Implementare `lib/news_normalize.php`**

```php
<?php
declare(strict_types=1);

/**
 * Funzioni pure di normalizzazione. Nessun accesso a rete o disco: sono la base
 * testabile su cui poggiano parser, store e fetcher.
 */

function news_normalize_url(string $url): string {
    $url = trim($url);
    [$url] = explode('#', $url, 2);
    [$base, $query] = array_pad(explode('?', $url, 2), 2, '');
    if ($query === '') {
        return $base;
    }
    parse_str($query, $params);
    $kept = array_filter(
        $params,
        static fn(string $k): bool => !str_starts_with(strtolower($k), 'utm_'),
        ARRAY_FILTER_USE_KEY
    );
    return $kept === [] ? $base : $base . '?' . http_build_query($kept);
}

function news_item_id(string $url): string {
    return sha1(news_normalize_url($url));
}

/**
 * Porta la stringa in UTF-8 valido. Senza mbstring si usa iconv; //IGNORE scarta
 * i byte non convertibili. Serve perché json_encode restituisce false su UTF-8
 * non valido, e un salvataggio non verificato produrrebbe un file vuoto.
 */
function news_to_utf8(string $s, string $from = 'UTF-8'): string {
    if (strtoupper($from) !== 'UTF-8') {
        $converted = @iconv($from, 'UTF-8//IGNORE', $s);
        if ($converted !== false) {
            $s = $converted;
        }
    }
    $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
    return $clean === false ? '' : $clean;
}

function news_clean_summary(string $html, int $max = 200): string {
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    if ($text === '') {
        return '';
    }
    if (preg_match('/^.{0,' . $max . '}(?=\s|$)/u', $text, $m) === 1 && $m[0] !== $text) {
        return rtrim($m[0], " ,.;:") . '…';
    }
    return $text;
}

/**
 * Restituisce una data ISO 8601. Gli RSS usano RFC-2822, il MASAF dd/mm/yyyy.
 * Una data non interpretabile ricade sul fallback (di norma l'istante di primo
 * rilevamento) invece di far sparire la voce o mandarla in cima all'elenco.
 */
function news_parse_date(string $raw, string $fallbackIso): string {
    $raw = trim($raw);
    if ($raw === '') {
        return $fallbackIso;
    }
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $raw, $m) === 1) {
        return (new DateTimeImmutable(
            "{$m[3]}-{$m[2]}-{$m[1]} 00:00:00",
            new DateTimeZone('Europe/Rome')
        ))->format('c');
    }
    try {
        return (new DateTimeImmutable($raw))->format('c');
    } catch (Throwable) {
        return $fallbackIso;
    }
}
```

- [ ] **Step 6: Eseguire i test e verificare che passino**

```bash
php tests/run.php
```

Atteso: `20 passati, 0 falliti`, exit code 0.

- [ ] **Step 7: Commit**

```bash
git add .gitignore tests/run.php tests/test_normalize.php lib/news_normalize.php
git commit -m "feat: normalizzazione url, charset, estratti e date per le news"
```

---

### Task 2: Parser RSS e parser MASAF

**Files:**
- Create: `lib/news_parsers.php`, `tests/test_parsers.php`, `tests/fixtures/rss_pesceinrete.xml`, `tests/fixtures/masaf_notizie.html`

**Interfaces:**
- Consumes: `news_to_utf8()`, `news_clean_summary()`, `news_parse_date()`, `news_item_id()` (Task 1)
- Produces:
  - `news_parse_rss(string $xml, string $sourceId, string $nowIso): array`
  - `news_parse_masaf(string $html, string $sourceId, array $keywords, string $nowIso): array`
  - Entrambe restituiscono una lista di `['id','source','title','url','date','summary']`, entrambe lanciano `RuntimeException` su input non parsabile.

- [ ] **Step 1: Scaricare le fixture reali**

```bash
cd "C:/Users/giang/Progetti Claude/masaf-decreti-pesca"
mkdir -p tests/fixtures
curl -s -A "Mozilla/5.0" --max-time 25 "https://www.pesceinrete.com/feed/" -o tests/fixtures/rss_pesceinrete.xml
curl -s -A "Mozilla/5.0" --max-time 25 "https://www.masaf.gov.it/flex/cm/pages/ServeBLOB.php/L/IT/IDPagina/9" -o tests/fixtures/masaf_notizie.html
```

Verificare che entrambi i file siano non vuoti: `wc -c tests/fixtures/*`. Le fixture congelano la forma reale delle fonti, così i test girano offline e un cambio di markup si manifesta come test rosso invece che come pagina vuota in produzione.

- [ ] **Step 2: Scrivere i test che falliscono**

`tests/test_parsers.php`:

```php
<?php
require_once __DIR__ . '/../lib/news_parsers.php';

$now = '2026-07-28T12:00:00+02:00';

// --- RSS ---
$xml = file_get_contents(__DIR__ . '/fixtures/rss_pesceinrete.xml');
$items = news_parse_rss($xml, 'pesceinrete', $now);
t_true(count($items) >= 5, 'RSS: almeno 5 voci estratte');
$first = $items[0];
t_eq($first['source'], 'pesceinrete', 'RSS: source valorizzato');
t_true($first['title'] !== '', 'RSS: titolo non vuoto');
t_true(str_starts_with($first['url'], 'http'), 'RSS: url assoluto');
t_eq(strlen($first['id']), 40, 'RSS: id sha1');
t_true(preg_match('/^\d{4}-\d{2}-\d{2}T/', $first['date']) === 1, 'RSS: data ISO 8601');
t_true(json_encode($items) !== false, 'RSS: risultato codificabile in JSON');
t_true(strip_tags($first['summary']) === $first['summary'], 'RSS: estratto senza tag');

$ids = array_column($items, 'id');
t_eq(count($ids), count(array_unique($ids)), 'RSS: nessun id duplicato');

// --- RSS non valido ---
$caught = false;
try { news_parse_rss('<<<non xml', 'x', $now); } catch (RuntimeException) { $caught = true; }
t_true($caught, 'RSS: input non valido solleva RuntimeException');

// --- MASAF ---
$html = file_get_contents(__DIR__ . '/fixtures/masaf_notizie.html');
$kw = ['pesca', 'ittic', 'acquacolt', 'tonno'];
$all = news_parse_masaf($html, 'masaf-notizie', [], $now);
t_true(count($all) >= 5, 'MASAF: senza filtro estrae le notizie');
t_true(
    !in_array(true, array_map(static fn($i) => str_contains($i['url'], '/flex/'), $all), true),
    'MASAF: esclusi i link di navigazione /flex/'
);

$filtered = news_parse_masaf($html, 'masaf-notizie', $kw, $now);
t_true(count($filtered) <= count($all), 'MASAF: il filtro non aggiunge voci');
foreach ($filtered as $i) {
    t_true(
        (bool) preg_match('/pesca|ittic|acquacolt|tonno/iu', $i['title']),
        'MASAF: ogni voce filtrata contiene una parola chiave'
    );
}
t_true(json_encode($filtered) !== false, 'MASAF: risultato codificabile (charset ISO-8859-1)');
```

- [ ] **Step 3: Eseguire i test e verificare che falliscano**

```bash
php tests/run.php
```

Atteso: errore fatale su `lib/news_parsers.php` mancante.

- [ ] **Step 4: Implementare `lib/news_parsers.php`**

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/news_normalize.php';

/**
 * Costruisce una voce normalizzata. Unico punto in cui si definisce la forma di
 * un item: parser diversi producono strutture identiche.
 */
function news_make_item(string $sourceId, string $title, string $url, string $rawDate, string $rawSummary, string $nowIso): array {
    return [
        'id'      => news_item_id($url),
        'source'  => $sourceId,
        'title'   => $title,
        'url'     => news_normalize_url($url),
        'date'    => news_parse_date($rawDate, $nowIso),
        'summary' => $rawSummary,
    ];
}

function news_parse_rss(string $xml, string $sourceId, string $nowIso): array {
    $prev = libxml_use_internal_errors(true);
    $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if ($sx === false || !isset($sx->channel->item)) {
        throw new RuntimeException('RSS non valido o senza item');
    }

    $items = [];
    foreach ($sx->channel->item as $node) {
        $url = trim((string) $node->link);
        $title = news_to_utf8(trim((string) $node->title));
        if ($url === '' || $title === '') {
            continue;
        }
        $items[news_item_id($url)] = news_make_item(
            $sourceId,
            $title,
            $url,
            trim((string) $node->pubDate),
            news_clean_summary(news_to_utf8((string) $node->description)),
            $nowIso
        );
    }
    return array_values($items);
}

/**
 * Le notizie MASAF hanno URL "puliti" (masaf.gov.it/<slug>), distinti dai link di
 * navigazione che passano tutti da /flex/. La data non sta nel testo del link:
 * si cerca un dd/mm/yyyy risalendo fino a tre antenati, con fallback su $nowIso.
 */
function news_parse_masaf(string $html, string $sourceId, array $keywords, string $nowIso): array {
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    // Le pagine sono ISO-8859-1: si converte prima di dare il markup a DOMDocument.
    $loaded = $dom->loadHTML(
        '<?xml encoding="UTF-8">' . news_to_utf8($html, 'ISO-8859-1'),
        LIBXML_NOWARNING | LIBXML_NOERROR
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if ($loaded === false) {
        throw new RuntimeException('HTML MASAF non parsabile');
    }

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query(
        '//a[starts-with(@href, "https://www.masaf.gov.it/")][not(contains(@href, "/flex/"))]'
    );

    $pattern = $keywords === []
        ? ''
        : '/' . implode('|', array_map('preg_quote', $keywords)) . '/iu';

    $items = [];
    foreach ($nodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $title = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
        // Sotto i 25 caratteri sono voci di menu e non titoli di notizia.
        if (strlen($title) < 25) {
            continue;
        }
        if ($pattern !== '' && preg_match($pattern, $title) !== 1) {
            continue;
        }

        $rawDate = '';
        $ancestor = $node->parentNode;
        for ($i = 0; $i < 3 && $ancestor instanceof DOMElement; $i++) {
            if (preg_match('#\b(\d{2}/\d{2}/\d{4})\b#', $ancestor->textContent, $m) === 1) {
                $rawDate = $m[1];
                break;
            }
            $ancestor = $ancestor->parentNode;
        }
        // Il titolo può cominciare con la data: la si toglie dal testo mostrato.
        $title = trim((string) preg_replace('#^\d{2}/\d{2}/\d{4}\s*#', '', $title));

        $url = $node->getAttribute('href');
        $items[news_item_id($url)] = news_make_item($sourceId, $title, $url, $rawDate, '', $nowIso);
    }
    return array_values($items);
}
```

- [ ] **Step 5: Eseguire i test e verificare che passino**

```bash
php tests/run.php
```

Atteso: tutti i test passati, exit code 0. Se il conteggio MASAF filtrato è 0, **non è un fallimento**: al momento della stesura 0 notizie su 10 riguardavano la pesca. Il test asserisce solo che le voci filtrate contengano le parole chiave, non che ce ne siano.

- [ ] **Step 6: Commit**

```bash
git add lib/news_parsers.php tests/test_parsers.php tests/fixtures/
git commit -m "feat: parser RSS e parser notizie MASAF con filtro per parole chiave"
```

---

### Task 3: Store — merge non distruttivo, potatura, salvataggio verificato

**Files:**
- Create: `lib/news_store.php`, `tests/test_store.php`

**Interfaces:**
- Consumes: niente da Task 1-2 (opera su array già normalizzati)
- Produces:
  - `news_store_load(string $path): array` — struttura vuota valida se il file manca
  - `news_store_merge(array $store, string $sourceId, array $items, string $nowIso): array`
  - `news_store_mark_failure(array $store, string $sourceId, string $error, string $nowIso): array`
  - `news_store_prune(array $store, string $nowIso, int $maxItems = 120, int $maxDays = 90): array`
  - `news_store_save(string $path, array $store): void` — lancia `RuntimeException` se `json_encode` fallisce
  - `news_source_is_stale(array $store, string $sourceId, string $nowIso, int $hours = 48): bool`

- [ ] **Step 1: Scrivere i test che falliscono**

`tests/test_store.php`:

```php
<?php
require_once __DIR__ . '/../lib/news_store.php';

$now = '2026-07-28T12:00:00+02:00';
$mk = static fn(string $id, string $date): array => [
    'id' => $id, 'source' => 's1', 'title' => "t$id",
    'url' => "https://x.it/$id", 'date' => $date, 'summary' => '',
];

// --- load su file assente ---
$empty = news_store_load(__DIR__ . '/fixtures/non-esiste.json');
t_eq($empty['items'], [], 'load: items vuoto se il file manca');
t_true(isset($empty['_meta']['sources']), 'load: _meta presente');

// --- merge aggiunge ---
$s = news_store_merge($empty, 's1', [$mk('a', $now), $mk('b', $now)], $now);
t_eq(count($s['items']), 2, 'merge: due voci aggiunte');
t_eq($s['_meta']['sources']['s1']['consecutive_failures'], 0, 'merge: fallimenti azzerati');
t_eq($s['_meta']['sources']['s1']['last_ok'], $now, 'merge: last_ok registrato');

// --- merge non duplica ---
$s2 = news_store_merge($s, 's1', [$mk('a', $now)], '2026-07-28T18:00:00+02:00');
t_eq(count($s2['items']), 2, 'merge: id già presente non duplica');

// --- merge conserva le voci di altre fonti ---
$s3 = news_store_merge($s2, 's2', [$mk('c', $now)], $now);
t_eq(count($s3['items']), 3, 'merge: fonte diversa si somma');
$s4 = news_store_merge($s3, 's2', [], $now);
t_eq(count($s4['items']), 3, 'merge: fonte senza novità non cancella nulla');

// --- fallimento non tocca gli items ---
$s5 = news_store_mark_failure($s4, 's1', 'HTTP 403', $now);
t_eq(count($s5['items']), 3, 'failure: gli items restano');
t_eq($s5['_meta']['sources']['s1']['consecutive_failures'], 1, 'failure: contatore a 1');
t_eq($s5['_meta']['sources']['s1']['last_error'], 'HTTP 403', 'failure: errore registrato');
$s6 = news_store_mark_failure($s5, 's1', 'HTTP 403', $now);
t_eq($s6['_meta']['sources']['s1']['consecutive_failures'], 2, 'failure: contatore incrementa');

// --- staleness ---
t_true(!news_source_is_stale($s6, 's2', $now), 's2 aggiornata ora: non stale');
t_true(news_source_is_stale($s6, 's2', '2026-07-31T12:00:00+02:00'), 'dopo 72h: stale');
t_true(news_source_is_stale($s6, 'mai-vista', $now), 'fonte senza last_ok: stale');

// --- prune per numero ---
$many = $empty;
for ($i = 0; $i < 150; $i++) {
    $many = news_store_merge($many, 's1', [$mk("id$i", $now)], $now);
}
$pruned = news_store_prune($many, $now, 120, 90);
t_eq(count($pruned['items']), 120, 'prune: tetto di 120 voci');

// --- prune per età ---
$aged = news_store_merge($empty, 's1', [
    $mk('vecchia', '2026-01-01T00:00:00+01:00'),
    $mk('nuova', $now),
], $now);
$pruned2 = news_store_prune($aged, $now, 120, 90);
t_eq(count($pruned2['items']), 1, 'prune: scartata la voce oltre 90 giorni');
t_eq($pruned2['items'][0]['id'], 'nuova', 'prune: conservata la recente');

// --- ordinamento ---
$ord = news_store_merge($empty, 's1', [
    $mk('vecchia', '2026-07-01T00:00:00+02:00'),
    $mk('recente', '2026-07-27T00:00:00+02:00'),
], $now);
$ord = news_store_prune($ord, $now, 120, 90);
t_eq($ord['items'][0]['id'], 'recente', 'prune: ordinamento cronologico discendente');

// --- save verificato ---
$tmp = sys_get_temp_dir() . '/news_test_' . getmypid() . '.json';
news_store_save($tmp, $ord);
t_true(file_exists($tmp), 'save: file creato');
$reread = news_store_load($tmp);
t_eq(count($reread['items']), 2, 'save: rilettura coerente');
unlink($tmp);

$caught = false;
try {
    $bad = $ord;
    $bad['items'][0]['title'] = "byte non validi \xFF\xFE";
    news_store_save($tmp, $bad);
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

Atteso: errore fatale su `lib/news_store.php` mancante.

- [ ] **Step 3: Implementare `lib/news_store.php`**

```php
<?php
declare(strict_types=1);

function news_store_empty(): array {
    return ['_meta' => ['last_run' => null, 'sources' => []], 'items' => []];
}

function news_store_load(string $path): array {
    if (!file_exists($path)) {
        return news_store_empty();
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
        return news_store_empty();
    }
    $data['_meta'] ??= ['last_run' => null, 'sources' => []];
    $data['_meta']['sources'] ??= [];
    return $data;
}

/**
 * Unisce le voci di una fonte a quelle già presenti. Non cancella mai: una fonte
 * che torna vuota o non risponde lascia intatto quanto già mostrato in pagina.
 */
function news_store_merge(array $store, string $sourceId, array $items, string $nowIso): array {
    $byId = [];
    foreach ($store['items'] as $existing) {
        $byId[$existing['id']] = $existing;
    }
    foreach ($items as $item) {
        if (isset($byId[$item['id']])) {
            // Si aggiornano titolo ed estratto (possono essere corretti a monte),
            // ma non la data: la prima rilevata è quella di pubblicazione.
            $byId[$item['id']]['title'] = $item['title'];
            $byId[$item['id']]['summary'] = $item['summary'];
            continue;
        }
        $byId[$item['id']] = $item;
    }
    $store['items'] = array_values($byId);
    $store['_meta']['sources'][$sourceId] = [
        'last_ok' => $nowIso,
        'last_error' => null,
        'consecutive_failures' => 0,
    ];
    return $store;
}

function news_store_mark_failure(array $store, string $sourceId, string $error, string $nowIso): array {
    $prev = $store['_meta']['sources'][$sourceId] ?? [
        'last_ok' => null, 'last_error' => null, 'consecutive_failures' => 0,
    ];
    $store['_meta']['sources'][$sourceId] = [
        'last_ok' => $prev['last_ok'],
        'last_error' => $error,
        'consecutive_failures' => ((int) $prev['consecutive_failures']) + 1,
    ];
    return $store;
}

function news_source_is_stale(array $store, string $sourceId, string $nowIso, int $hours = 48): bool {
    $lastOk = $store['_meta']['sources'][$sourceId]['last_ok'] ?? null;
    if ($lastOk === null) {
        return true;
    }
    return (strtotime($nowIso) - strtotime($lastOk)) > $hours * 3600;
}

/**
 * Ordina per data discendente e applica i due tetti: 90 giorni e 120 voci,
 * complessivi e non per fonte.
 */
function news_store_prune(array $store, string $nowIso, int $maxItems = 120, int $maxDays = 90): array {
    $cutoff = strtotime($nowIso) - $maxDays * 86400;
    $items = array_values(array_filter(
        $store['items'],
        static fn(array $i): bool => strtotime($i['date']) >= $cutoff
    ));
    usort($items, static fn(array $a, array $b): int => strtotime($b['date']) <=> strtotime($a['date']));
    $store['items'] = array_slice($items, 0, $maxItems);
    return $store;
}

function news_store_save(string $path, array $store): void {
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

Atteso: tutti passati, exit code 0.

- [ ] **Step 5: Commit**

```bash
git add lib/news_store.php tests/test_store.php
git commit -m "feat: store delle news con merge non distruttivo e salvataggio verificato"
```

---

### Task 4: Fetcher CLI e configurazione delle fonti

**Files:**
- Create: `news_fetcher.php`, `data/news_sources.json`
- Test: verifica manuale a riga di comando (descritta negli step)

**Interfaces:**
- Consumes: tutto `lib/news_*.php` (Task 1-3)
- Produces: `data/news.json` popolato; exit code `0` se almeno una fonte è andata a buon fine, `1` se sono cadute tutte.

- [ ] **Step 1: Creare `data/news_sources.json`**

```json
{
  "sources": [
    {
      "id": "pesceinrete",
      "label": "Pesce in Rete",
      "type": "rss",
      "url": "https://www.pesceinrete.com/feed/",
      "color": "62 115 104"
    },
    {
      "id": "assoittica",
      "label": "Assoittica",
      "type": "rss",
      "url": "https://www.assoittica.it/feed/",
      "color": "92 107 158"
    },
    {
      "id": "masaf-notizie",
      "label": "MASAF · Notizie",
      "type": "masaf",
      "url": "https://www.masaf.gov.it/flex/cm/pages/ServeBLOB.php/L/IT/IDPagina/9",
      "color": "156 122 46",
      "keywords": ["pesca", "pescher", "ittic", "acquacolt", "mollusch", "vongol", "tonno", "FEAMPA", "GSA", "marittim"]
    },
    {
      "id": "masaf-comunicati",
      "label": "MASAF · Comunicati",
      "type": "masaf",
      "url": "https://www.masaf.gov.it/flex/cm/pages/ServeBLOB.php/L/IT/IDPagina/331",
      "color": "122 95 34",
      "keywords": ["pesca", "pescher", "ittic", "acquacolt", "mollusch", "vongol", "tonno", "FEAMPA", "GSA", "marittim"]
    }
  ]
}
```

- [ ] **Step 2: Implementare `news_fetcher.php`**

```php
<?php
declare(strict_types=1);

/**
 * Scarica le fonti configurate in data/news_sources.json, normalizza le voci e
 * le unisce a data/news.json.
 *
 * Ogni fonte è isolata: una che fallisce non impedisce alle altre di aggiornarsi.
 * Se cadono tutte, si aggiorna solo _meta (salute e last_run) e gli items restano
 * intatti, così la pagina continua a mostrare le notizie già raccolte e l'avviso
 * di fonte ferma può comunque scattare.
 *
 * Uso: php news_fetcher.php
 */

require_once __DIR__ . '/lib/news_normalize.php';
require_once __DIR__ . '/lib/news_parsers.php';
require_once __DIR__ . '/lib/news_store.php';

$dataDir    = __DIR__ . '/data';
$storeFile  = $dataDir . '/news.json';
$sourceFile = $dataDir . '/news_sources.json';
$logFile    = $dataDir . '/news.log';

function news_log(string $msg, string $logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

/** Come in scraper.php: senza openssl i wrapper https:// non esistono. */
function news_fetch_url(string $url): string {
    $cmd = sprintf(
        'curl -s -L -A %s --max-time 25 %s',
        escapeshellarg('Mozilla/5.0 (Windows NT 10.0; Win64; x64) masaf-decreti-pesca-scraper/1.0'),
        escapeshellarg($url)
    );
    $body = shell_exec($cmd);
    if ($body === null || $body === '') {
        throw new RuntimeException("download fallito (curl): $url");
    }
    return $body;
}

$config = json_decode((string) @file_get_contents($sourceFile), true);
if (!is_array($config) || empty($config['sources'])) {
    news_log('ERRORE: data/news_sources.json mancante o non valido', $logFile);
    exit(1);
}

$now   = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('c');
$store = news_store_load($storeFile);

$ok = 0;
$ko = 0;
foreach ($config['sources'] as $source) {
    $id = (string) $source['id'];
    try {
        $body = news_fetch_url((string) $source['url']);
        $items = match ((string) $source['type']) {
            'rss'   => news_parse_rss($body, $id, $now),
            'masaf' => news_parse_masaf($body, $id, $source['keywords'] ?? [], $now),
            default => throw new RuntimeException("tipo fonte sconosciuto: {$source['type']}"),
        };
        $before = count($store['items']);
        $store  = news_store_merge($store, $id, $items, $now);
        $nuove  = count($store['items']) - $before;
        news_log("$id: " . count($items) . " voci lette, $nuove nuove", $logFile);
        $ok++;
    } catch (Throwable $e) {
        $store = news_store_mark_failure($store, $id, $e->getMessage(), $now);
        news_log("$id: ERRORE " . $e->getMessage(), $logFile);
        $ko++;
    }
}

$store['_meta']['last_run'] = $now;
if ($ok > 0) {
    $store = news_store_prune($store, $now);
}

try {
    news_store_save($storeFile, $store);
} catch (Throwable $e) {
    news_log('ERRORE salvataggio: ' . $e->getMessage(), $logFile);
    exit(1);
}

news_log("Fonti ok: $ok, fallite: $ko, voci totali: " . count($store['items']), $logFile);
exit($ok > 0 ? 0 : 1);
```

- [ ] **Step 3: Eseguire a freddo e verificare il risultato**

```bash
php news_fetcher.php
echo "exit: $?"
php -r "\$d=json_decode(file_get_contents('data/news.json'),true);
echo 'voci: '.count(\$d['items']).PHP_EOL;
foreach(array_count_values(array_column(\$d['items'],'source')) as \$s=>\$n) echo \"  \$s: \$n\".PHP_EOL;"
```

Atteso: exit `0`, voci > 0, almeno due fonti rappresentate (le due RSS). Le fonti MASAF possono legittimamente contribuire 0 voci.

- [ ] **Step 4: Eseguire una seconda volta e verificare che non duplichi**

```bash
php news_fetcher.php >/dev/null
php -r "\$d=json_decode(file_get_contents('data/news.json'),true);
\$ids=array_column(\$d['items'],'id');
echo 'voci: '.count(\$ids).' | id unici: '.count(array_unique(\$ids)).PHP_EOL;"
```

Atteso: i due numeri coincidono.

- [ ] **Step 5: Verificare gli accenti**

```bash
php -r "\$d=json_decode(file_get_contents('data/news.json'),true);
foreach(\$d['items'] as \$i) if(preg_match('/[àèéìòù]/u',\$i['title'])) { echo \$i['title'].PHP_EOL; break; }"
```

Atteso: un titolo con accenti resi correttamente (`à`, `è`), non `Ã ` né punti interrogativi.

- [ ] **Step 6: Verificare l'isolamento di una fonte rotta**

Aggiungere temporaneamente a `data/news_sources.json` una fonte con URL inesistente:

```json
{ "id": "rotta", "label": "Test", "type": "rss", "url": "https://non-esiste.invalid/feed/", "color": "0 0 0" }
```

```bash
php news_fetcher.php
echo "exit: $?"
```

Atteso: exit `0`, log con `rotta: ERRORE`, le altre fonti comunque aggiornate, `items` non diminuito. Poi **rimuovere** la fonte di test da `news_sources.json`.

- [ ] **Step 7: Commit**

```bash
git add news_fetcher.php data/news_sources.json data/news.json
git commit -m "feat: fetcher delle news con isolamento per fonte ed exit code parlante"
```

---

### Task 5: Estrarre il CSS condiviso

Task a sé perché è l'unica modifica a `index.php` che tocca il rendering esistente: va verificata isolatamente, prima che la pagina news le si appoggi sopra.

**Files:**
- Create: `assets/style.css`
- Modify: `index.php` (il blocco `<style>`, righe 61-149)

- [ ] **Step 1: Fotografare l'HTML attuale come riferimento**

```bash
cd "C:/Users/giang/Progetti Claude/masaf-decreti-pesca"
curl -s http://localhost:8000/ > ../index_prima.html
wc -c ../index_prima.html
```

Non usare `/tmp`: in Git Bash su Windows viene tradotto in un percorso che gli strumenti nativi (PHP, Python) non risolvono, e il confronto fallirebbe con "file non trovato".

- [ ] **Step 2: Spostare il CSS**

Copiare il contenuto fra `<style>` e `</style>` di `index.php` (righe 62-148, cioè tutto tranne i tag) in `assets/style.css`, senza modificarne una riga.

- [ ] **Step 3: Sostituire il blocco in `index.php`**

Rimpiazzare l'intero blocco `<style>…</style>` con:

```html
<link rel="stylesheet" href="assets/style.css">
```

- [ ] **Step 4: Verificare che la resa non cambi**

```bash
curl -s -o /dev/null -w "css: %{http_code}\n" http://localhost:8000/assets/style.css
curl -s -o /dev/null -w "home: %{http_code}\n" http://localhost:8000/
```

Atteso: entrambi `200`. Aprire la home nel browser e confrontare a vista con `/tmp/index_prima.html`: sfondo, card colorate a sinistra, chip di filtro e tema scuro devono essere identici.

- [ ] **Step 5: Commit**

```bash
git add assets/style.css index.php
git commit -m "refactor: estrae il CSS di index.php in assets/style.css"
```

---

### Task 6: Pagina `news.php`

**Files:**
- Create: `news.php`
- Modify: `index.php` (masthead: link alla pagina news), `assets/style.css` (stili della lista news)

**Interfaces:**
- Consumes: `data/news.json`, `data/news_sources.json`, `news_source_is_stale()` da `lib/news_store.php`

- [ ] **Step 1: Aggiungere gli stili in coda ad `assets/style.css`**

```css
/* --- lista news --- */
.news-list { display: flex; flex-direction: column; gap: 0.9rem; }
.news-item { display: flex; flex-direction: column; gap: 0.4rem; background: rgb(var(--paper-raised));
  border: 1px solid rgb(var(--line)); border-left: 3px solid rgb(var(--src-c)); border-radius: 6px;
  padding: 0.95rem 1.1rem; }
.news-head { display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem; }
.news-badge { font-family: ui-monospace, monospace; font-size: 0.7rem; letter-spacing: 0.06em;
  text-transform: uppercase; color: rgb(var(--src-c)); }
.news-date { font-family: ui-monospace, monospace; font-variant-numeric: tabular-nums;
  font-size: 0.72rem; color: rgb(var(--muted)); margin-left: auto; }
.news-item h3 { font-family: Georgia, serif; font-weight: 600; font-size: 1.02rem; line-height: 1.3; margin: 0; }
.news-item h3 a { color: rgb(var(--ink)); text-decoration: none; }
.news-item h3 a:hover { text-decoration: underline; }
.news-item p { font-size: 0.88rem; color: rgb(var(--muted)); margin: 0; line-height: 1.55; }
.news-stale { border: 1px dashed rgb(var(--warn)); border-radius: 8px; padding: 0.9rem 1.1rem;
  margin-bottom: 1.6rem; font-size: 0.86rem; color: rgb(var(--warn)); }
.news-empty { border: 1px solid rgb(var(--line)); border-radius: 8px; padding: 2rem 1.2rem;
  text-align: center; color: rgb(var(--muted)); }
.topbar { display: flex; gap: 1rem; margin-bottom: 1.2rem; font-size: 0.82rem; }
```

- [ ] **Step 2: Scrivere `news.php`**

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/news_store.php';

$dataDir = __DIR__ . '/data';
$store   = news_store_load($dataDir . '/news.json');
$config  = json_decode((string) @file_get_contents($dataDir . '/news_sources.json'), true) ?? ['sources' => []];

$sources = [];
foreach ($config['sources'] ?? [] as $s) {
    $sources[$s['id']] = $s;
}

$now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('c');

$stale = [];
foreach ($sources as $id => $s) {
    if (news_source_is_stale($store, $id, $now)) {
        $stale[] = $s['label'];
    }
}

$items = $store['items'];
$lastRun = $store['_meta']['last_run'] ?? null;
$lastRunLabel = $lastRun ? date('d/m/Y H:i', strtotime($lastRun)) : 'mai eseguito';

function h(int|string|null $s): string {
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>News — Il mondo della pesca in Italia</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap">

  <div class="topbar"><a href="index.php">← Registro decreti</a></div>

  <div class="masthead">
    <p class="eyebrow">Rassegna · pesca professionale · aggiornamento automatico</p>
    <h1>News dal mondo della pesca</h1>
    <p class="lede">Notizie da stampa di settore e fonti istituzionali, raccolte automaticamente.
      A differenza del registro dei decreti, questa pagina non è curata a mano: i contenuti
      appartengono alle rispettive testate e sono riportati con titolo, estratto e link all'originale.</p>
    <div class="meta-row">
      <span>Ultimo aggiornamento: <strong><?= h($lastRunLabel) ?></strong></span>
      <span>Notizie: <strong><?= count($items) ?></strong></span>
      <span>Fonti attive: <strong><?= count($sources) - count($stale) ?>/<?= count($sources) ?></strong></span>
    </div>
  </div>

  <?php if ($stale): ?>
  <div class="news-stale">
    ⚠ Nessun aggiornamento da oltre 48 ore da: <strong><?= h(implode(', ', $stale)) ?></strong>.
    Le notizie già raccolte restano consultabili.
  </div>
  <?php endif; ?>

  <div class="year-filter" role="group" aria-label="Filtra per fonte">
    <span class="label">Filtra per fonte</span>
    <button type="button" class="yr-chip" data-src="all" aria-pressed="true">Tutte</button>
    <?php foreach ($sources as $id => $s): ?>
    <button type="button" class="yr-chip" data-src="<?= h($id) ?>" aria-pressed="false"><?= h($s['label']) ?></button>
    <?php endforeach; ?>
  </div>

  <?php if (!$items): ?>
  <div class="news-empty">
    Nessuna notizia ancora raccolta. Esegui <code>php news_fetcher.php</code> per popolare la pagina.
  </div>
  <?php else: ?>
  <div class="news-list">
    <?php foreach ($items as $item): ?>
      <?php $src = $sources[$item['source']] ?? ['label' => $item['source'], 'color' => '107 100 89']; ?>
    <article class="news-item" data-src="<?= h($item['source']) ?>" style="--src-c: <?= h($src['color']) ?>">
      <div class="news-head">
        <span class="news-badge"><?= h($src['label']) ?></span>
        <span class="news-date"><?= h(date('d/m/Y', strtotime($item['date']))) ?></span>
      </div>
      <h3><a href="<?= h($item['url']) ?>" target="_blank" rel="noopener"><?= h($item['title']) ?></a></h3>
      <?php if (!empty($item['summary'])): ?>
      <p><?= h($item['summary']) ?></p>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <footer class="note">
    Pagina generata da <code>data/news.json</code>, aggiornato da <code>news_fetcher.php</code> ogni 6 ore
    via Task Scheduler. Si conservano al massimo 120 notizie o 90 giorni. Titoli, estratti e link
    appartengono alle testate indicate: per il testo integrale seguire il collegamento alla fonte.
  </footer>

</div>

<script>
(function () {
  var buttons = document.querySelectorAll(".yr-chip");
  var items = document.querySelectorAll(".news-item");
  buttons.forEach(function (btn) {
    btn.addEventListener("click", function () {
      buttons.forEach(function (b) { b.setAttribute("aria-pressed", "false"); });
      btn.setAttribute("aria-pressed", "true");
      var src = btn.getAttribute("data-src");
      items.forEach(function (el) {
        el.style.display = (src === "all" || el.getAttribute("data-src") === src) ? "" : "none";
      });
    });
  });
})();
</script>
</body>
</html>
```

- [ ] **Step 3: Aggiungere il link in `index.php`**

Subito dopo l'apertura di `<div class="wrap">` (riga 152), inserire:

```html
  <div class="topbar"><a href="news.php">News dal mondo della pesca →</a></div>
```

- [ ] **Step 4: Verificare la pagina**

```bash
curl -s -o /dev/null -w "news: %{http_code}\n" http://localhost:8000/news.php
curl -s http://localhost:8000/news.php | grep -c "news-item"
curl -s http://localhost:8000/news.php | grep -o "Notizie: <strong>[0-9]*"
```

Atteso: `200`, numero di `news-item` pari alle voci in `news.json`, contatore coerente. Aprire nel browser: badge colorati per fonte, filtro funzionante, accenti corretti.

- [ ] **Step 5: Verificare il caso "prima installazione"**

```bash
mv data/news.json data/news.json.tmp
curl -s -o /dev/null -w "news senza dati: %{http_code}\n" http://localhost:8000/news.php
curl -s http://localhost:8000/news.php | grep -c "news-empty"
mv data/news.json.tmp data/news.json
```

Atteso: `200` e il riquadro "Nessuna notizia ancora raccolta" — mai un errore PHP.

- [ ] **Step 6: Verificare che la home non sia regredita**

```bash
curl -s http://localhost:8000/ | grep -o "Voci catalogate: <strong>[0-9]*"
```

Atteso: `40`, come prima delle modifiche.

- [ ] **Step 7: Commit**

```bash
git add news.php index.php assets/style.css
git commit -m "feat: pagina news con filtro per fonte e avviso di fonte ferma"
```

---

### Task 7: Schedulazione e verifica finale

**Files:**
- Modify: nessuno (configurazione di sistema)

- [ ] **Step 1: Creare il task pianificato**

```powershell
$php = "C:\Users\giang\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
$action = New-ScheduledTaskAction -Execute $php -Argument '"C:\Users\giang\Progetti Claude\masaf-decreti-pesca\news_fetcher.php"'
$trigger = New-ScheduledTaskTrigger -Daily -At 7am
$trigger.Repetition = (New-ScheduledTaskTrigger -Once -At 7am -RepetitionInterval (New-TimeSpan -Hours 6) -RepetitionDuration (New-TimeSpan -Days 1)).Repetition
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable
Register-ScheduledTask -TaskName 'MASAF News Pesca' -Action $action -Trigger $trigger -Settings $settings
```

Le impostazioni sulla batteria sono le stesse applicate al task dei decreti: senza `-AllowStartIfOnBatteries` il task viene rifiutato con `0x800710E0` quando il portatile non è alimentato, che è il guasto già osservato su questo sistema.

- [ ] **Step 2: Verificare che il task giri davvero**

```powershell
Start-ScheduledTask -TaskName 'MASAF News Pesca'
Start-Sleep -Seconds 20
Get-ScheduledTaskInfo -TaskName 'MASAF News Pesca' | Format-List LastRunTime, LastTaskResult, NextRunTime
Get-Content "C:\Users\giang\Progetti Claude\masaf-decreti-pesca\data\news.log" -Tail 5
```

Atteso: `LastTaskResult: 0` e righe di log con l'orario dell'esecuzione appena lanciata.

- [ ] **Step 3: Eseguire l'intera suite di test**

```bash
cd "C:/Users/giang/Progetti Claude/masaf-decreti-pesca"
php tests/run.php
```

Atteso: `0 falliti`, exit code 0.

- [ ] **Step 4: Aggiornare `.gitignore` e committare**

```bash
printf 'server.log\ndata/scraper.log\ndata/news.log\n' > .gitignore
git add .gitignore
git commit -m "chore: esclude il log del fetcher news dal versionamento"
```

---

## Copertura dello spec

| Requisito dello spec | Task |
|---|---|
| Fetcher e pagina come unità isolate via `news.json` | 4, 6 |
| Fonti come dato in `news_sources.json` | 4 |
| Parser RSS e parser MASAF con filtro parole chiave | 2 |
| Deduplica su URL normalizzato (fragment, `utm_*`) | 1 |
| `summary` ≤ 200 caratteri, mai testo integrale | 1, 2 |
| Isolamento per fonte in `try/catch` | 4 |
| Merge non distruttivo | 3 |
| Salute per fonte e avviso oltre 48h | 3, 6 |
| Exit code 0/1 leggibile dal Task Scheduler | 4 |
| Guardia su `iconv` e `json_encode` | 1, 3 |
| Tetto 120 voci / 90 giorni, complessivo | 3 |
| Date ISO nel file, `dd/mm/yyyy` in pagina, fallback | 1, 6 |
| CSS estratto in `assets/style.css` | 5 |
| Task Scheduler separato ogni 6 ore | 7 |
| Le 6 verifiche elencate nello spec | 4 (3-6), 6 (5), 7 (3) |

**Scostamento consapevole dallo spec:** lo spec dice che a fonti tutte cadute `news.json` "non viene riscritto". Il piano scrive comunque, ma **solo `_meta`**: senza questo, `consecutive_failures` non avanzerebbe mai e l'avviso di fonte ferma non scatterebbe proprio nel caso peggiore. Gli `items` restano intatti, che è l'intento del requisito.
