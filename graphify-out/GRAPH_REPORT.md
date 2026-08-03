# Graph Report - masaf-decreti-pesca  (2026-08-03)

## Corpus Check
- 30 files · ~53,047 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 129 nodes · 144 edges · 12 communities detected
- Extraction: 85% EXTRACTED · 15% INFERRED · 0% AMBIGUOUS · INFERRED: 22 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- [[_COMMUNITY_Community 0|Community 0]]
- [[_COMMUNITY_Community 1|Community 1]]
- [[_COMMUNITY_Community 2|Community 2]]
- [[_COMMUNITY_Community 3|Community 3]]
- [[_COMMUNITY_Community 4|Community 4]]
- [[_COMMUNITY_Community 5|Community 5]]
- [[_COMMUNITY_Community 6|Community 6]]
- [[_COMMUNITY_Community 8|Community 8]]
- [[_COMMUNITY_Community 9|Community 9]]
- [[_COMMUNITY_Community 10|Community 10]]
- [[_COMMUNITY_Community 13|Community 13]]
- [[_COMMUNITY_Community 20|Community 20]]

## God Nodes (most connected - your core abstractions)
1. `bandi_parse_archivio()` - 11 edges
2. `news_item_id()` - 7 edges
3. `prova()` - 7 edges
4. `bandi_da_feed()` - 6 edges
5. `news_make_item()` - 6 edges
6. `bandi_testo()` - 5 edges
7. `news_normalize_url()` - 5 edges
8. `news_parse_rss()` - 5 edges
9. `news_to_utf8()` - 4 edges
10. `news_clean_summary()` - 4 edges

## Surprising Connections (you probably didn't know these)
- `bandi_testo()` --calls--> `bandi_da_feed()`  [INFERRED]
  lib\bandi_normalize.php → lib\bandi_parser.php
- `bandi_regioni_da_classi()` --calls--> `bandi_parse_archivio()`  [INFERRED]
  lib\bandi_normalize.php → lib\bandi_parser.php
- `bandi_e_terminato()` --calls--> `bandi_parse_archivio()`  [INFERRED]
  lib\bandi_normalize.php → lib\bandi_parser.php
- `bandi_parse_archivio()` --calls--> `news_to_utf8()`  [INFERRED]
  lib\bandi_parser.php → lib\news_normalize.php
- `bandi_parse_archivio()` --calls--> `news_clean_summary()`  [INFERRED]
  lib\bandi_parser.php → lib\news_normalize.php

## Communities

### Community 0 - "Community 0"
Cohesion: 0.31
Nodes (7): bandi_e_terminato(), bandi_parse_data_italiana(), bandi_regioni_da_classi(), bandi_testo(), bandi_titolo_da_scopo(), bandi_parse_archivio(), bandi_voce()

### Community 1 - "Community 1"
Cohesion: 0.4
Nodes (9): bandi_da_feed(), news_clean_summary(), news_item_id(), news_normalize_url(), news_parse_date(), news_to_utf8(), news_make_item(), news_parse_masaf() (+1 more)

### Community 2 - "Community 2"
Cohesion: 0.36
Nodes (8): atteso(), carica(), contatore(), digita(), norm(), ok(), prova(), rendi()

### Community 3 - "Community 3"
Cohesion: 0.25
Nodes (2): extract_year(), item_year()

### Community 4 - "Community 4"
Cohesion: 0.28
Nodes (4): bandi_store_empty(), bandi_store_load(), bandi_store_merge(), bandi_valore_vuoto()

### Community 5 - "Community 5"
Cohesion: 0.29
Nodes (2): news_store_empty(), news_store_load()

### Community 6 - "Community 6"
Cohesion: 0.4
Nodes (2): extract_year(), item_year()

### Community 8 - "Community 8"
Cohesion: 0.4
Nodes (2): allegati_descrizione(), allegati_parse()

### Community 9 - "Community 9"
Cohesion: 0.4
Nodes (2): news_date_label(), data_it()

### Community 10 - "Community 10"
Cohesion: 0.6
Nodes (3): anyVisible(), apply(), norm()

### Community 13 - "Community 13"
Cohesion: 0.67
Nodes (2): bandi_fetch(), bandi_totale_api()

### Community 20 - "Community 20"
Cohesion: 1.0
Nodes (2): t_eq(), t_true()

## Knowledge Gaps
- **Thin community `Community 3`** (9 nodes): `index.php`, `allegato_desc()`, `data_lunga()`, `extract_year()`, `h()`, `item_year()`, `ref_data()`, `scheda_url()`, `search_blob()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 5`** (8 nodes): `news_source_is_stale()`, `news_store_empty()`, `news_store_load()`, `news_store_mark_failure()`, `news_store_merge()`, `news_store_prune()`, `news_store_save()`, `news_store.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 6`** (6 nodes): `allegato_desc()`, `extract_year()`, `h()`, `item_year()`, `search_blob()`, `registro.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 8`** (6 nodes): `allegati_confronta()`, `allegati_descrizione()`, `allegati_pagine_catalogo()`, `allegati_parse()`, `allegati_peso_it()`, `allegati.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 9`** (5 nodes): `bandi.php`, `news_date_label()`, `bandi_search_blob()`, `data_it()`, `h()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 13`** (4 nodes): `bandi_fetcher.php`, `bandi_fetch()`, `bandi_log()`, `bandi_totale_api()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 20`** (3 nodes): `run.php`, `t_eq()`, `t_true()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `bandi_parse_archivio()` connect `Community 0` to `Community 1`?**
  _High betweenness centrality (0.018) - this node is a cross-community bridge._
- **Why does `news_date_label()` connect `Community 9` to `Community 1`?**
  _High betweenness centrality (0.011) - this node is a cross-community bridge._
- **Are the 9 inferred relationships involving `bandi_parse_archivio()` (e.g. with `news_to_utf8()` and `bandi_testo()`) actually correct?**
  _`bandi_parse_archivio()` has 9 INFERRED edges - model-reasoned connections that need verification._
- **Are the 5 inferred relationships involving `news_item_id()` (e.g. with `bandi_parse_archivio()` and `bandi_da_feed()`) actually correct?**
  _`news_item_id()` has 5 INFERRED edges - model-reasoned connections that need verification._
- **Are the 4 inferred relationships involving `bandi_da_feed()` (e.g. with `bandi_testo()` and `news_item_id()`) actually correct?**
  _`bandi_da_feed()` has 4 INFERRED edges - model-reasoned connections that need verification._
- **Are the 3 inferred relationships involving `news_make_item()` (e.g. with `news_item_id()` and `news_normalize_url()`) actually correct?**
  _`news_make_item()` has 3 INFERRED edges - model-reasoned connections that need verification._