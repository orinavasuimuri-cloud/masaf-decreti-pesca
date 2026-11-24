# Graph Report - masaf-decreti-pesca  (2026-11-24)

## Corpus Check
- 87 files · ~177,177 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 20 file(s) not represented in the graph (top: .css 8, (none) 6, .xml 3)

## Summary
- 453 nodes · 571 edges · 55 communities (20 shown, 35 thin omitted)
- Extraction: 82% EXTRACTED · 18% INFERRED · 0% AMBIGUOUS · INFERRED: 105 edges (avg confidence: 0.89)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `0e0ccc70`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- news_item_id
- rete_scarica
- gazzetta_parser.php
- bandi_store.php
- gazzetta_store.php
- news_store.php
- filters.test.mjs
- index.php
- Bandi pesca per regione — design
- Pubblicazione del sito
- bandi.php
- allegati.php
- Sezione news sul mondo della pesca — design
- accesso.php
- Gazzetta Ufficiale come fonte istituzionale — piano di implementazione
- curatela.php
- Fonti regionali: cosa è leggibile e cosa no
- test_pagine_markup.php
- filters.js
- aggiorna_config.php
- lancia_fetcher.php
- Gazzetta Ufficiale come fonte istituzionale

## God Nodes (most connected - your core abstractions)
1. `Pubblicazione del sito` - 13 edges
2. `news_item_id()` - 13 edges
3. `Gazzetta Ufficiale come fonte istituzionale` - 13 edges
4. `rete_scarica()` - 12 edges
5. `bandi_da_feed()` - 11 edges
6. `bandi_parse_archivio()` - 11 edges
7. `news_parse_rss()` - 11 edges
8. `Sezione news sul mondo della pesca — design` - 11 edges
9. `Bandi pesca per regione — design` - 11 edges
10. `news_to_utf8()` - 10 edges

## Surprising Connections (you probably didn't know these)
- ``data/bandi.json`` --references--> `bandi_da_feed()`  [INFERRED]
  docs/superpowers/specs/2026-07-30-bandi-pesca-regioni-design.md → lib/bandi_parser.php
- `Cosa si è verificato sul campo (04/08/2026)` --references--> `bandi_da_feed()`  [INFERRED]
  docs/superpowers/specs/2026-08-04-gazzetta-ufficiale-design.md → lib/bandi_parser.php
- ``data/gazzetta.json`` --references--> `news_item_id()`  [INFERRED]
  docs/superpowers/specs/2026-08-04-gazzetta-ufficiale-design.md → lib/news_normalize.php
- `Global Constraints` --references--> `news_to_utf8()`  [INFERRED]
  docs/superpowers/plans/2026-07-28-news-pesca.md → lib/news_normalize.php
- `Come sono state provate le fonti` --references--> `news_parse_rss()`  [INFERRED]
  docs/FONTI_REGIONALI.md → lib/news_parsers.php

## Import Cycles
- None detected.

## Communities (55 total, 35 thin omitted)

### Community 0 - "news_item_id"
Cohesion: 0.12
Nodes (26): Task 2: Parser RSS e parser MASAF, Global Constraints, Task 2: Parser dell'archivio, delle categorie e dei feed, Architettura, campania_bandi_voci(), campania_parse_atti(), campania_parse_data_numerica(), bandi_e_terminato() (+18 more)

### Community 1 - "rete_scarica"
Cohesion: 0.11
Nodes (16): bandi_fetch(), bandi_log(), bandi_totale_api(), {closure#1}(), rete_disponibili(), rete_scarica(), rete_scarica_curl_ext(), rete_scarica_https() (+8 more)

### Community 2 - "gazzetta_parser.php"
Cohesion: 0.13
Nodes (20): Struttura dei file, Task 1: Lettura del sommario, Task 2: Scomposizione del titolo e codice dell'atto, Task 3: Filtro in tema e destinazione, Task 4: Archivio e numeri saltati, Task 5: Il fetcher, Task 6: La coda GU nel riquadro "da rivedere", Task 7: Link ai Bollettini regionali (+12 more)

### Community 3 - "bandi_store.php"
Cohesion: 0.12
Nodes (17): Bandi pesca per regione — Implementation Plan, Copertura dello spec, Estensione FLAG (2026-07-31), File Structure, Task 1: Normalizzazione — date italiane, stato, titolo, slug, Task 3: Store — merge non distruttivo, copertura, salvataggio verificato, Task 5: Fetcher CLI, Task 6: Pagina `bandi.php` (+9 more)

### Community 4 - "gazzetta_store.php"
Cohesion: 0.12
Nodes (14): {closure#1}(), {closure#2}(), {closure#3}(), {closure#5}(), gz_fetch(), gz_log(), {closure#1}(), gazzetta_items_merge() (+6 more)

### Community 5 - "news_store.php"
Cohesion: 0.10
Nodes (13): Copertura dello spec, File Structure, Global Constraints, Sezione news pesca — Implementation Plan, Task 1: Repository, runner di test e normalizzazione, Task 3: Store — merge non distruttivo, potatura, salvataggio verificato, Task 4: Fetcher CLI e configurazione delle fonti, Task 5: Estrarre il CSS condiviso (+5 more)

### Community 6 - "filters.test.mjs"
Cohesion: 0.14
Nodes (14): Task 4: Dati curati — regioni, calendari ufficiali, FLAG e fonti, atteso(), carica(), contatore(), digita(), fails, filtersJs, norm() (+6 more)

### Community 7 - "index.php"
Cohesion: 0.08
Nodes (6): decreto_nuovo(), extract_year(), item_year(), dinamica_data_da_testo(), dinamica_ticker(), news_safe_url()

### Community 8 - "Bandi pesca per regione — design"
Cohesion: 0.11
Nodes (18): Bandi pesca per regione — design, Comportamento in caso di errore, Contesto e vincoli, Dalle categorie della fonte alla regione, `data/bandi.json`, `data/bandi_regioni.json`, Directory di link ufficiali, Fonte primaria dei dati (+10 more)

### Community 9 - "Pubblicazione del sito"
Cohesion: 0.12
Nodes (16): Che cos'è, Codici di uscita, Contatti, Cosa non serve, Il bottone "Aggiorna ora", L'uscita in rete, Passo 1 — verificare il server, Passo 2 — installare (+8 more)

### Community 10 - "bandi.php"
Cohesion: 0.11
Nodes (5): {closure#3}(), {closure#4}(), data_it(), giorni_alla_scadenza(), news_date_label()

### Community 11 - "allegati.php"
Cohesion: 0.18
Nodes (5): ca_fetch(), {closure#1}(), allegati_descrizione(), allegati_parse(), allegati_peso_it()

### Community 12 - "Sezione news sul mondo della pesca — design"
Cohesion: 0.14
Nodes (13): Architettura, Comportamento in caso di errore, Conservazione e date, Contesto e vincoli, `data/news.json`, `data/news_sources.json`, Fonti, Fuori ambito (+5 more)

### Community 13 - "accesso.php"
Cohesion: 0.20
Nodes (19): accesso_admin(), accesso_avvia_sessione(), accesso_config(), accesso_csrf_ok(), accesso_csrf_token(), accesso_email_autorizzata(), accesso_entra(), accesso_esci() (+11 more)

### Community 16 - "Gazzetta Ufficiale come fonte istituzionale — piano di implementazione"
Cohesion: 0.36
Nodes (6): Fuori perimetro, Gazzetta Ufficiale come fonte istituzionale — piano di implementazione, Global Constraints, Verifica finale, t_eq(), t_true()

### Community 18 - "curatela.php"
Cohesion: 0.10
Nodes (23): atti_da_rivedere(), {closure#2}(), catalogo_inserisci(), curatela_con_lock(), curatela_leggi(), curatela_scrivi(), curatela_voce(), eurlex_aggiorna() (+15 more)

### Community 20 - "Fonti regionali: cosa è leggibile e cosa no"
Cohesion: 0.33
Nodes (5): Come sono state provate le fonti, Fonti regionali: cosa è leggibile e cosa no, In esercizio, Perché le altre no, Valutata e non fatta: la Puglia

### Community 22 - "test_pagine_markup.php"
Cohesion: 0.47
Nodes (3): pm_count(), pm_valori(), pm_xpath()

### Community 23 - "filters.js"
Cohesion: 0.60
Nodes (3): anyVisible(), apply(), norm()

### Community 50 - "Gazzetta Ufficiale come fonte istituzionale"
Cohesion: 0.13
Nodes (14): Architettura, Bollettini regionali, Buchi di copertura, Coda di revisione, Cosa si è verificato sul campo (04/08/2026), `data/gazzetta_fonti.json`, `data/gazzetta.json`, Decisioni prese (+6 more)

## Knowledge Gaps
- **35 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `gazzetta_corrisponde()` connect `gazzetta_parser.php` to `news_item_id`?**
  _High betweenness centrality (0.230) - this node is a cross-community bridge._
- **Are the 11 inferred relationships involving `news_item_id()` (e.g. with `Task 2: Parser RSS e parser MASAF` and `Global Constraints`) actually correct?**
  _`news_item_id()` has 11 INFERRED edges - model-reasoned connections that need verification._
- **Should `news_item_id` be split into smaller, more focused modules?**
  _Cohesion score 0.11538461538461539 - nodes in this community are weakly interconnected._
- **Why does `gazzetta_da_rivedere()` connect `curatela.php` to `gazzetta_parser.php`?**
  _High betweenness centrality (0.199) - this node is a cross-community bridge._
- **Are the 6 inferred relationships involving `rete_scarica()` (e.g. with `bandi_fetch()` and `ca_fetch()`) actually correct?**
  _`rete_scarica()` has 6 INFERRED edges - model-reasoned connections that need verification._
- **Should `rete_scarica` be split into smaller, more focused modules?**
  _Cohesion score 0.11 - nodes in this community are weakly interconnected._
- **Are the 9 inferred relationships involving `bandi_da_feed()` (e.g. with `Estensione FLAG (2026-07-31)` and ``data/bandi.json``) actually correct?**
  _`bandi_da_feed()` has 9 INFERRED edges - model-reasoned connections that need verification._