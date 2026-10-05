# news_parse_rss()

> God node · 7 connections · [C:\Users\giang\Progetti Claude\masaf-decreti-pesca\lib\news_parsers.php](file:///C:/Users/giang/Progetti%20Claude/masaf-decreti-pesca/lib/news_parsers.php#L27)

## Call Trace Diagram

```mermaid
sequenceDiagram
    participant P0 as news_parse_rss()
    participant P1 as news_item_id()
    participant P2 as bandi_parse_archivio()
    participant P3 as bandi_testo()
    participant P4 as news_normalize_url()
    participant P5 as news_to_utf8()
    participant P6 as bandi_voce()
    participant P7 as news_clean_summary()
    participant P8 as bandi_parse_data_italiana()
    participant P9 as bandi_titolo_da_scopo()
    participant P10 as bandi_regioni_da_classi()
    participant P11 as bandi_e_terminato()
    participant P12 as bandi_da_feed()
    participant P13 as keywords_pattern()
    participant P14 as keywords_corrisponde()
    participant P15 as news_make_item()
    participant P16 as news_parse_masaf()
    participant P17 as campania_bandi_voci()
    P0->>+ P1: calls
    P1-->>- P0: return
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
    P2->>+ P9: calls
    P9-->>- P2: return
    P2->>+ P10: calls
    P10-->>- P2: return
    P2->>+ P11: calls
    P11-->>- P2: return
    P1->>+ P12: calls
    P12-->>- P1: return
    P12->>+ P1: calls
    P1-->>- P12: return
    P12->>+ P3: calls
    P3-->>- P12: return
    P12->>+ P4: calls
    P4-->>- P12: return
    P12->>+ P13: calls
    P13-->>- P12: return
    P12->>+ P14: calls
    P14-->>- P12: return
    P12->>+ P6: calls
    P6-->>- P12: return
    P12->>+ P7: calls
    P7-->>- P12: return
    P1->>+ P0: calls
    P0-->>- P1: return
    P1->>+ P4: calls
    P4-->>- P1: return
    P1->>+ P15: calls
    P15-->>- P1: return
    P1->>+ P16: calls
    P16-->>- P1: return
    P1->>+ P17: calls
    P17-->>- P1: return
    P0->>+ P15: calls
    P15-->>- P0: return
    P0->>+ P13: calls
    P13-->>- P0: return
    P0->>+ P5: calls
    P5-->>- P0: return
    P0->>+ P14: calls
    P14-->>- P0: return
    P0->>+ P7: calls
    P7-->>- P0: return
```

## Connections by Relation

### calls
- [[news_item_id()]] `INFERRED`
- [[news_make_item()]] `EXTRACTED`
- [[keywords_pattern()]] `INFERRED`
- [[news_to_utf8()]] `INFERRED`
- [[keywords_corrisponde()]] `INFERRED`
- [[news_clean_summary()]] `INFERRED`

### contains
- [[news_parsers.php]] `EXTRACTED`

---

*Part of the graphify knowledge wiki. See [[index]] to navigate.*