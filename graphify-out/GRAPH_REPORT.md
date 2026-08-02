# Graph Report - masaf-decreti-pesca  (2026-08-02)

## Corpus Check
- 20 files · ~44,119 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 74 nodes · 89 edges · 8 communities detected
- Extraction: 75% EXTRACTED · 25% INFERRED · 0% AMBIGUOUS · INFERRED: 22 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- [[_COMMUNITY_Community 0|Community 0]]
- [[_COMMUNITY_Community 1|Community 1]]
- [[_COMMUNITY_Community 2|Community 2]]
- [[_COMMUNITY_Community 3|Community 3]]
- [[_COMMUNITY_Community 5|Community 5]]
- [[_COMMUNITY_Community 6|Community 6]]
- [[_COMMUNITY_Community 7|Community 7]]
- [[_COMMUNITY_Community 10|Community 10]]

## God Nodes (most connected - your core abstractions)
1. `bandi_parse_archivio()` - 11 edges
2. `news_item_id()` - 7 edges
3. `bandi_da_feed()` - 6 edges
4. `news_make_item()` - 6 edges
5. `bandi_testo()` - 5 edges
6. `news_normalize_url()` - 5 edges
7. `news_parse_rss()` - 5 edges
8. `news_to_utf8()` - 4 edges
9. `news_clean_summary()` - 4 edges
10. `news_parse_masaf()` - 4 edges

## Surprising Connections (you probably didn't know these)
- `bandi_regioni_da_classi()` --calls--> `bandi_parse_archivio()`  [INFERRED]
  lib\bandi_normalize.php → lib\bandi_parser.php
- `bandi_e_terminato()` --calls--> `bandi_parse_archivio()`  [INFERRED]
  lib\bandi_normalize.php → lib\bandi_parser.php
- `bandi_parse_archivio()` --calls--> `news_to_utf8()`  [INFERRED]
  lib\bandi_parser.php → lib\news_normalize.php
- `bandi_parse_archivio()` --calls--> `news_clean_summary()`  [INFERRED]
  lib\bandi_parser.php → lib\news_normalize.php
- `bandi_parse_archivio()` --calls--> `news_normalize_url()`  [INFERRED]
  lib\bandi_parser.php → lib\news_normalize.php

## Communities

### Community 0 - "Community 0"
Cohesion: 0.3
Nodes (8): bandi_e_terminato(), bandi_parse_data_italiana(), bandi_regioni_da_classi(), bandi_testo(), bandi_titolo_da_scopo(), bandi_da_feed(), bandi_parse_archivio(), bandi_voce()

### Community 1 - "Community 1"
Cohesion: 0.42
Nodes (8): news_clean_summary(), news_item_id(), news_normalize_url(), news_parse_date(), news_to_utf8(), news_make_item(), news_parse_masaf(), news_parse_rss()

### Community 2 - "Community 2"
Cohesion: 0.28
Nodes (4): bandi_store_empty(), bandi_store_load(), bandi_store_merge(), bandi_valore_vuoto()

### Community 3 - "Community 3"
Cohesion: 0.29
Nodes (2): news_store_empty(), news_store_load()

### Community 5 - "Community 5"
Cohesion: 0.5
Nodes (2): extract_year(), item_year()

### Community 6 - "Community 6"
Cohesion: 0.5
Nodes (2): news_date_label(), data_it()

### Community 7 - "Community 7"
Cohesion: 0.67
Nodes (2): bandi_fetch(), bandi_totale_api()

### Community 10 - "Community 10"
Cohesion: 1.0
Nodes (2): t_eq(), t_true()

## Knowledge Gaps
- **Thin community `Community 3`** (8 nodes): `news_source_is_stale()`, `news_store_empty()`, `news_store_load()`, `news_store_mark_failure()`, `news_store_merge()`, `news_store_prune()`, `news_store_save()`, `news_store.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 5`** (5 nodes): `index.php`, `extract_year()`, `h()`, `item_year()`, `search_blob()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 6`** (4 nodes): `bandi.php`, `news_date_label()`, `data_it()`, `h()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 7`** (4 nodes): `bandi_fetcher.php`, `bandi_fetch()`, `bandi_log()`, `bandi_totale_api()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 10`** (3 nodes): `run.php`, `t_eq()`, `t_true()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `bandi_parse_archivio()` connect `Community 0` to `Community 1`?**
  _High betweenness centrality (0.053) - this node is a cross-community bridge._
- **Why does `news_date_label()` connect `Community 6` to `Community 1`?**
  _High betweenness centrality (0.025) - this node is a cross-community bridge._
- **Why does `news_item_id()` connect `Community 1` to `Community 0`?**
  _High betweenness centrality (0.021) - this node is a cross-community bridge._
- **Are the 9 inferred relationships involving `bandi_parse_archivio()` (e.g. with `news_to_utf8()` and `bandi_testo()`) actually correct?**
  _`bandi_parse_archivio()` has 9 INFERRED edges - model-reasoned connections that need verification._
- **Are the 5 inferred relationships involving `news_item_id()` (e.g. with `bandi_parse_archivio()` and `bandi_da_feed()`) actually correct?**
  _`news_item_id()` has 5 INFERRED edges - model-reasoned connections that need verification._
- **Are the 4 inferred relationships involving `bandi_da_feed()` (e.g. with `bandi_testo()` and `news_item_id()`) actually correct?**
  _`bandi_da_feed()` has 4 INFERRED edges - model-reasoned connections that need verification._
- **Are the 3 inferred relationships involving `news_make_item()` (e.g. with `news_item_id()` and `news_normalize_url()`) actually correct?**
  _`news_make_item()` has 3 INFERRED edges - model-reasoned connections that need verification._