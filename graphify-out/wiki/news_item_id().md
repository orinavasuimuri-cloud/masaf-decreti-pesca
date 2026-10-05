# news_item_id()

> God node · 8 connections · [C:\Users\giang\Progetti Claude\masaf-decreti-pesca\lib\news_normalize.php](file:///C:/Users/giang/Progetti%20Claude/masaf-decreti-pesca/lib/news_normalize.php#L25)

## Call Trace Diagram

```mermaid
sequenceDiagram
    participant P0 as news_item_id()
    participant P1 as bandi_parse_archivio()
    participant P2 as bandi_testo()
    participant P3 as bandi_da_feed()
    participant P4 as campania_parse_atti()
    participant P5 as bandi_parse_data_italiana()
    participant P6 as bandi_titolo_da_scopo()
    participant P7 as campania_parse_data_numerica()
    participant P8 as news_normalize_url()
    participant P9 as news_make_item()
    participant P10 as news_to_utf8()
    participant P11 as bandi_voce()
    participant P12 as news_clean_summary()
    participant P13 as bandi_regioni_da_classi()
    participant P14 as bandi_e_terminato()
    participant P15 as news_parse_rss()
    participant P16 as news_parse_masaf()
    participant P17 as campania_bandi_voci()
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
    P1->>+ P8: calls
    P8-->>- P1: return
    P8->>+ P1: calls
    P1-->>- P8: return
    P8->>+ P0: calls
    P0-->>- P8: return
    P8->>+ P3: calls
    P3-->>- P8: return
    P8->>+ P4: calls
    P4-->>- P8: return
    P8->>+ P9: calls
    P9-->>- P8: return
    P1->>+ P10: calls
    P10-->>- P1: return
    P1->>+ P11: calls
    P11-->>- P1: return
    P1->>+ P12: calls
    P12-->>- P1: return
    P1->>+ P5: calls
    P5-->>- P1: return
    P1->>+ P6: calls
    P6-->>- P1: return
    P1->>+ P13: calls
    P13-->>- P1: return
    P1->>+ P14: calls
    P14-->>- P1: return
    P0->>+ P3: calls
    P3-->>- P0: return
    P0->>+ P15: calls
    P15-->>- P0: return
    P0->>+ P8: calls
    P8-->>- P0: return
    P0->>+ P9: calls
    P9-->>- P0: return
    P0->>+ P16: calls
    P16-->>- P0: return
    P0->>+ P17: calls
    P17-->>- P0: return
```

## Connections by Relation

### calls
- [[bandi_parse_archivio()]] `INFERRED`
- [[bandi_da_feed()]] `INFERRED`
- [[news_parse_rss()]] `INFERRED`
- [[news_normalize_url()]] `EXTRACTED`
- [[news_make_item()]] `INFERRED`
- [[news_parse_masaf()]] `INFERRED`
- [[campania_bandi_voci()]] `INFERRED`

### contains
- [[news_normalize.php]] `EXTRACTED`

---

*Part of the graphify knowledge wiki. See [[index]] to navigate.*