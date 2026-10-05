# bandi_parse_archivio()

> God node · 11 connections · [C:\Users\giang\Progetti Claude\masaf-decreti-pesca\lib\bandi_parser.php](file:///C:/Users/giang/Progetti%20Claude/masaf-decreti-pesca/lib/bandi_parser.php#L87)

## Call Trace Diagram

```mermaid
sequenceDiagram
    participant P0 as bandi_parse_archivio()
    participant P1 as news_item_id()
    participant P2 as bandi_da_feed()
    participant P3 as bandi_testo()
    participant P4 as news_normalize_url()
    participant P5 as keywords_pattern()
    participant P6 as keywords_corrisponde()
    participant P7 as bandi_voce()
    participant P8 as news_clean_summary()
    participant P9 as news_parse_rss()
    participant P10 as news_make_item()
    participant P11 as news_to_utf8()
    participant P12 as news_parse_masaf()
    participant P13 as campania_bandi_voci()
    participant P14 as bandi_parse_data_italiana()
    participant P15 as bandi_titolo_da_scopo()
    participant P16 as bandi_regioni_da_classi()
    participant P17 as bandi_e_terminato()
    P0->>+ P1: calls
    P1-->>- P0: return
    P1->>+ P0: calls
    P0-->>- P1: return
    P1->>+ P2: calls
    P2-->>- P1: return
    P2->>+ P1: calls
    P1-->>- P2: return
    P2->>+ P3: calls
    P3-->>- P2: return
    P2->>+ P4: calls
    P4-->>- P2: return
    P2->>+ P5: calls
    P5-->>- P2: return
    P2->>+ P6: calls
    P6-->>- P2: return
    P2->>+ P7: calls
    P7-->>- P2: return
    P2->>+ P8: calls
    P8-->>- P2: return
    P1->>+ P9: calls
    P9-->>- P1: return
    P9->>+ P1: calls
    P1-->>- P9: return
    P9->>+ P10: calls
    P10-->>- P9: return
    P9->>+ P5: calls
    P5-->>- P9: return
    P9->>+ P11: calls
    P11-->>- P9: return
    P9->>+ P6: calls
    P6-->>- P9: return
    P9->>+ P8: calls
    P8-->>- P9: return
    P1->>+ P4: calls
    P4-->>- P1: return
    P1->>+ P10: calls
    P10-->>- P1: return
    P1->>+ P12: calls
    P12-->>- P1: return
    P1->>+ P13: calls
    P13-->>- P1: return
    P0->>+ P3: calls
    P3-->>- P0: return
    P0->>+ P4: calls
    P4-->>- P0: return
    P0->>+ P11: calls
    P11-->>- P0: return
    P0->>+ P7: calls
    P7-->>- P0: return
    P0->>+ P8: calls
    P8-->>- P0: return
    P0->>+ P14: calls
    P14-->>- P0: return
    P0->>+ P15: calls
    P15-->>- P0: return
    P0->>+ P16: calls
    P16-->>- P0: return
    P0->>+ P17: calls
    P17-->>- P0: return
```

## Connections by Relation

### calls
- [[news_item_id()]] `INFERRED`
- [[bandi_testo()]] `INFERRED`
- [[news_normalize_url()]] `INFERRED`
- [[news_to_utf8()]] `INFERRED`
- [[bandi_voce()]] `EXTRACTED`
- [[news_clean_summary()]] `INFERRED`
- [[bandi_parse_data_italiana()]] `INFERRED`
- [[bandi_titolo_da_scopo()]] `INFERRED`
- [[bandi_regioni_da_classi()]] `INFERRED`
- [[bandi_e_terminato()]] `INFERRED`

### contains
- [[bandi_parser.php]] `EXTRACTED`

---

*Part of the graphify knowledge wiki. See [[index]] to navigate.*