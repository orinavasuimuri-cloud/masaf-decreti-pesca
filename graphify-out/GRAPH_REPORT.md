# Graph Report - C:\Users\giang\Progetti Claude\masaf-decreti-pesca  (2026-08-12)

## Corpus Check
- 42 files · ~106,873 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 167 nodes · 203 edges · 37 communities detected
- Extraction: 83% EXTRACTED · 17% INFERRED · 0% AMBIGUOUS · INFERRED: 34 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- [[_COMMUNITY_Community 0|Community 0]]
- [[_COMMUNITY_Community 1|Community 1]]
- [[_COMMUNITY_Community 2|Community 2]]
- [[_COMMUNITY_Community 3|Community 3]]
- [[_COMMUNITY_Community 4|Community 4]]
- [[_COMMUNITY_Community 5|Community 5]]
- [[_COMMUNITY_Community 6|Community 6]]
- [[_COMMUNITY_Community 7|Community 7]]
- [[_COMMUNITY_Community 8|Community 8]]
- [[_COMMUNITY_Community 9|Community 9]]
- [[_COMMUNITY_Community 10|Community 10]]
- [[_COMMUNITY_Community 11|Community 11]]
- [[_COMMUNITY_Community 12|Community 12]]
- [[_COMMUNITY_Community 13|Community 13]]
- [[_COMMUNITY_Community 14|Community 14]]
- [[_COMMUNITY_Community 15|Community 15]]
- [[_COMMUNITY_Community 16|Community 16]]
- [[_COMMUNITY_Community 17|Community 17]]
- [[_COMMUNITY_Community 18|Community 18]]
- [[_COMMUNITY_Community 19|Community 19]]
- [[_COMMUNITY_Community 20|Community 20]]
- [[_COMMUNITY_Community 21|Community 21]]
- [[_COMMUNITY_Community 22|Community 22]]
- [[_COMMUNITY_Community 23|Community 23]]
- [[_COMMUNITY_Community 24|Community 24]]
- [[_COMMUNITY_Community 25|Community 25]]
- [[_COMMUNITY_Community 26|Community 26]]
- [[_COMMUNITY_Community 27|Community 27]]
- [[_COMMUNITY_Community 28|Community 28]]
- [[_COMMUNITY_Community 29|Community 29]]
- [[_COMMUNITY_Community 30|Community 30]]
- [[_COMMUNITY_Community 31|Community 31]]
- [[_COMMUNITY_Community 32|Community 32]]
- [[_COMMUNITY_Community 33|Community 33]]
- [[_COMMUNITY_Community 34|Community 34]]
- [[_COMMUNITY_Community 35|Community 35]]
- [[_COMMUNITY_Community 36|Community 36]]

## God Nodes (most connected - your core abstractions)
1. `bandi_parse_archivio()` - 11 edges
2. `bandi_da_feed()` - 8 edges
3. `news_item_id()` - 8 edges
4. `bandi_testo()` - 7 edges
5. `prova()` - 7 edges
6. `campania_parse_atti()` - 6 edges
7. `news_normalize_url()` - 6 edges
8. `news_make_item()` - 6 edges
9. `news_parse_masaf()` - 6 edges
10. `gazzetta_corrisponde()` - 5 edges

## Surprising Connections (you probably didn't know these)
- `campania_parse_atti()` --calls--> `news_to_utf8()`  [INFERRED]
  C:\Users\giang\Progetti Claude\masaf-decreti-pesca\lib\bandi_campania.php → C:\Users\giang\Progetti Claude\masaf-decreti-pesca\lib\news_normalize.php
- `campania_parse_atti()` --calls--> `news_normalize_url()`  [INFERRED]
  C:\Users\giang\Progetti Claude\masaf-decreti-pesca\lib\bandi_campania.php → C:\Users\giang\Progetti Claude\masaf-decreti-pesca\lib\news_normalize.php
- `campania_bandi_voci()` --calls--> `news_item_id()`  [INFERRED]
  C:\Users\giang\Progetti Claude\masaf-decreti-pesca\lib\bandi_campania.php → C:\Users\giang\Progetti Claude\masaf-decreti-pesca\lib\news_normalize.php
- `bandi_testo()` --calls--> `bandi_da_feed()`  [INFERRED]
  C:\Users\giang\Progetti Claude\masaf-decreti-pesca\lib\bandi_normalize.php → C:\Users\giang\Progetti Claude\masaf-decreti-pesca\lib\bandi_parser.php
- `bandi_regioni_da_classi()` --calls--> `bandi_parse_archivio()`  [INFERRED]
  C:\Users\giang\Progetti Claude\masaf-decreti-pesca\lib\bandi_normalize.php → C:\Users\giang\Progetti Claude\masaf-decreti-pesca\lib\bandi_parser.php

## Communities

### Community 0 - "Community 0"

Cohesion: 0.24
Nodes (10): campania_bandi_voci(), campania_parse_atti(), campania_parse_data_numerica(), bandi_e_terminato(), bandi_parse_data_italiana(), bandi_regioni_da_classi(), bandi_testo(), bandi_titolo_da_scopo() (+2 more)

### Community 1 - "Community 1"

Cohesion: 0.2
Nodes (13): atteso(), carica(), contatore(), digita(), fails, filtersJs, norm(), ok() (+5 more)

### Community 2 - "Community 2"

Cohesion: 0.19
Nodes (6): gazzetta_items_merge(), gazzetta_saltato_scomponi(), gazzetta_store_empty(), gazzetta_store_load(), gazzetta_store_merge(), gazzetta_store_recupera()

### Community 3 - "Community 3"

Cohesion: 0.31
Nodes (11): bandi_da_feed(), keywords_corrisponde(), keywords_pattern(), news_clean_summary(), news_item_id(), news_normalize_url(), news_parse_date(), news_to_utf8() (+3 more)

### Community 4 - "Community 4"

Cohesion: 0.27
Nodes (10): gazzetta_codice_atto(), gazzetta_corrisponde(), gazzetta_destinazione(), gazzetta_dom(), gazzetta_in_tema(), gazzetta_normalizza_testo(), gazzetta_parse_archivio_anno(), gazzetta_parse_sommario_html() (+2 more)

### Community 5 - "Community 5"

Cohesion: 0.28
Nodes (4): bandi_store_empty(), bandi_store_load(), bandi_store_merge(), bandi_valore_vuoto()

### Community 6 - "Community 6"

Cohesion: 0.29
Nodes (2): news_store_empty(), news_store_load()

### Community 7 - "Community 7"
_Unable to determine domain due to missing code entities._
Cohesion: 0.4
Nodes (2): extract_year(), item_year()

### Community 8 - "Community 8"

Cohesion: 0.33
Nodes (0): 

### Community 9 - "Community 9"

Cohesion: 0.4
Nodes (2): allegati_descrizione(), allegati_parse()

### Community 10 - "Community 10"

Cohesion: 0.67
Nodes (5): lock_acquisisci(), lock_acquisisci_entro(), lock_apribile(), lock_o_esci(), lock_rilascia()

### Community 11 - "Community 11"

Cohesion: 0.6
Nodes (3): anyVisible(), apply(), norm()

### Community 12 - "Community 12"
_Unable to determine domain due to missing code entities._
Cohesion: 0.4
Nodes (2): data_it(), news_date_label()

### Community 13 - "Community 13"

Cohesion: 0.4
Nodes (0): 

### Community 14 - "Community 14"
_Unable to determine domain due to missing code entities._
Cohesion: 0.67
Nodes (2): bandi_fetch(), bandi_totale_api()

### Community 15 - "Community 15"
_Unable to determine domain due to missing code entities._
Cohesion: 0.67
Nodes (0): 

### Community 16 - "Community 16"
_Unable to determine domain due to missing code entities._
Cohesion: 0.67
Nodes (0): 

### Community 17 - "Community 17"
_Unable to determine domain due to missing code entities._
Cohesion: 0.67
Nodes (0): 

### Community 18 - "Community 18"
_Unable to determine domain due to missing code entities._
Cohesion: 0.67
Nodes (0): 

### Community 19 - "Community 19"

Cohesion: 0.67
Nodes (0): 

### Community 20 - "Community 20"
_Unable to determine domain due to missing code entities._
Cohesion: 1.0
Nodes (2): t_eq(), t_true()

### Community 21 - "Community 21"
_Unable to determine domain due to missing code entities._
Cohesion: 1.0
Nodes (0): 

### Community 22 - "Community 22"
_Unable to determine domain due to missing code entities._
Cohesion: 1.0
Nodes (0): 

### Community 23 - "Community 23"
_Unable to determine domain due to missing code entities._
Cohesion: 1.0
Nodes (0): 

### Community 24 - "Community 24"
_Unable to determine domain due to missing code entities._
Cohesion: 1.0
Nodes (0): 

### Community 25 - "Community 25"
_Unable to determine domain due to missing code entities._
Cohesion: 1.0
Nodes (0): 

### Community 26 - "Community 26"
_Unable to determine domain due to missing code entities._
Cohesion: 1.0
Nodes (0): 

### Community 27 - "Community 27"
_Unable to determine domain due to missing code entities._
Cohesion: 1.0
Nodes (0): 

### Community 28 - "Community 28"
_Unable to determine domain due to missing code entities._
Cohesion: 1.0
Nodes (0): 

### Community 29 - "Community 29"
_Unable to determine domain due to missing code entities._
Cohesion: 1.0
Nodes (0): 

### Community 30 - "Community 30"
_Unable to determine domain due to missing code entities._
Cohesion: 1.0
Nodes (0): 

### Community 31 - "Community 31"
_Unable to determine domain due to missing code entities._
Cohesion: 1.0
Nodes (0): 

### Community 32 - "Community 32"
_Unable to determine domain due to missing code entities._
Cohesion: 1.0
Nodes (0): 

### Community 33 - "Community 33"
_Unable to determine domain due to missing code entities._
Cohesion: 1.0
Nodes (0): 

### Community 34 - "Community 34"
_Unable to determine domain due to missing code entities._
Cohesion: 1.0
Nodes (0): 

### Community 35 - "Community 35"
_Unable to determine domain due to missing code entities._
Cohesion: 1.0
Nodes (0): 

### Community 36 - "Community 36"
_Unable to determine domain due to missing code entities._
Cohesion: 1.0
Nodes (0): 

## Knowledge Gaps
- **5 isolated node(s):** `ROOT`, `filtersJs`, `TMP`, `pass`, `fails`
  These have ≤1 connection - possible missing edges or undocumented components.
- **Thin community `Community 21`** (2 nodes): `check_ambiente.php`, `esito()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 22`** (2 nodes): `archivio_pagina()`, `archivio_pagina.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 23`** (1 nodes): `test_allegati.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 24`** (1 nodes): `test_archivio_pagina.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 25`** (1 nodes): `test_bandi_campania.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 26`** (1 nodes): `test_bandi_flag.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 27`** (1 nodes): `test_bandi_normalize.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 28`** (1 nodes): `test_bandi_parser.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 29`** (1 nodes): `test_bandi_store.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 30`** (1 nodes): `test_gazzetta_parser.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 31`** (1 nodes): `test_gazzetta_store.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 32`** (1 nodes): `test_keywords.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 33`** (1 nodes): `test_lock.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 34`** (1 nodes): `test_normalize.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 35`** (1 nodes): `test_parsers.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 36`** (1 nodes): `test_store.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.