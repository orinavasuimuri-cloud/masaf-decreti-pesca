# Graph Report - masaf-decreti-pesca  (2026-08-06)

## Corpus Check
- 38 files · ~69,443 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 165 nodes · 184 edges · 14 communities detected
- Extraction: 85% EXTRACTED · 15% INFERRED · 0% AMBIGUOUS · INFERRED: 28 edges (avg confidence: 0.8)
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
- [[_COMMUNITY_Community 11|Community 11]]
- [[_COMMUNITY_Community 14|Community 14]]
- [[_COMMUNITY_Community 15|Community 15]]
- [[_COMMUNITY_Community 21|Community 21]]

## God Nodes (most connected - your core abstractions)
1. `bandi_parse_archivio()` - 11 edges
2. `bandi_da_feed()` - 8 edges
3. `news_item_id()` - 7 edges
4. `prova()` - 7 edges
5. `news_make_item()` - 6 edges
6. `news_parse_masaf()` - 6 edges
7. `bandi_testo()` - 5 edges
8. `gazzetta_corrisponde()` - 5 edges
9. `gazzetta_voci()` - 5 edges
10. `news_normalize_url()` - 5 edges

## Surprising Connections (you probably didn't know these)
- `bandi_regioni_da_classi()` --calls--> `bandi_parse_archivio()`  [INFERRED]
  lib\bandi_normalize.php → lib\bandi_parser.php
- `bandi_e_terminato()` --calls--> `bandi_parse_archivio()`  [INFERRED]
  lib\bandi_normalize.php → lib\bandi_parser.php
- `gazzetta_corrisponde()` --calls--> `keywords_corrisponde()`  [INFERRED]
  lib\gazzetta_parser.php → lib\keywords.php
- `gazzetta_corrisponde()` --calls--> `keywords_pattern()`  [INFERRED]
  lib\gazzetta_parser.php → lib\keywords.php
- `news_parse_date()` --calls--> `news_make_item()`  [INFERRED]
  lib\news_normalize.php → lib\news_parsers.php

## Communities

### Community 0 - "Community 0"
Cohesion: 0.17
Nodes (18): bandi_e_terminato(), bandi_parse_data_italiana(), bandi_regioni_da_classi(), bandi_testo(), bandi_titolo_da_scopo(), bandi_da_feed(), bandi_parse_archivio(), bandi_voce() (+10 more)

### Community 1 - "Community 1"
Cohesion: 0.22
Nodes (2): gazzetta_store_empty(), gazzetta_store_load()

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
Cohesion: 0.39
Nodes (6): gazzetta_codice_atto(), gazzetta_corrisponde(), gazzetta_destinazione(), gazzetta_in_tema(), gazzetta_scompone_titolo(), gazzetta_voci()

### Community 6 - "Community 6"
Cohesion: 0.29
Nodes (2): news_store_empty(), news_store_load()

### Community 8 - "Community 8"
Cohesion: 0.4
Nodes (2): allegati_descrizione(), allegati_parse()

### Community 9 - "Community 9"
Cohesion: 0.4
Nodes (2): extract_year(), item_year()

### Community 10 - "Community 10"
Cohesion: 0.4
Nodes (2): news_date_label(), data_it()

### Community 11 - "Community 11"
Cohesion: 0.6
Nodes (3): anyVisible(), apply(), norm()

### Community 14 - "Community 14"
Cohesion: 0.67
Nodes (2): bandi_fetch(), bandi_totale_api()

### Community 15 - "Community 15"
Cohesion: 0.83
Nodes (3): lock_acquisisci(), lock_o_esci(), lock_rilascia()

### Community 21 - "Community 21"
Cohesion: 1.0
Nodes (2): t_eq(), t_true()

## Knowledge Gaps
- **Thin community `Community 1`** (10 nodes): `gazzetta_da_travasare()`, `gazzetta_feed_fermo()`, `gazzetta_marca_travasate()`, `gazzetta_numeri_saltati()`, `gazzetta_store_empty()`, `gazzetta_store_load()`, `gazzetta_store_mark_failure()`, `gazzetta_store_merge()`, `gazzetta_store_save()`, `gazzetta_store.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 3`** (9 nodes): `index.php`, `allegato_desc()`, `data_lunga()`, `extract_year()`, `h()`, `item_year()`, `ref_data()`, `scheda_url()`, `search_blob()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 6`** (8 nodes): `news_source_is_stale()`, `news_store_empty()`, `news_store_load()`, `news_store_mark_failure()`, `news_store_merge()`, `news_store_prune()`, `news_store_save()`, `news_store.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 8`** (6 nodes): `allegati_confronta()`, `allegati_descrizione()`, `allegati_pagine_catalogo()`, `allegati_parse()`, `allegati_peso_it()`, `allegati.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 9`** (6 nodes): `allegato_desc()`, `extract_year()`, `h()`, `item_year()`, `search_blob()`, `registro.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 10`** (5 nodes): `bandi.php`, `news_date_label()`, `bandi_search_blob()`, `data_it()`, `h()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 14`** (4 nodes): `bandi_fetcher.php`, `bandi_fetch()`, `bandi_log()`, `bandi_totale_api()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 21`** (3 nodes): `run.php`, `t_eq()`, `t_true()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `gazzetta_corrisponde()` connect `Community 5` to `Community 0`?**
  _High betweenness centrality (0.018) - this node is a cross-community bridge._
- **Are the 9 inferred relationships involving `bandi_parse_archivio()` (e.g. with `news_to_utf8()` and `bandi_testo()`) actually correct?**
  _`bandi_parse_archivio()` has 9 INFERRED edges - model-reasoned connections that need verification._
- **Are the 6 inferred relationships involving `bandi_da_feed()` (e.g. with `keywords_pattern()` and `bandi_testo()`) actually correct?**
  _`bandi_da_feed()` has 6 INFERRED edges - model-reasoned connections that need verification._
- **Are the 5 inferred relationships involving `news_item_id()` (e.g. with `bandi_parse_archivio()` and `bandi_da_feed()`) actually correct?**
  _`news_item_id()` has 5 INFERRED edges - model-reasoned connections that need verification._
- **Are the 3 inferred relationships involving `news_make_item()` (e.g. with `news_item_id()` and `news_normalize_url()`) actually correct?**
  _`news_make_item()` has 3 INFERRED edges - model-reasoned connections that need verification._