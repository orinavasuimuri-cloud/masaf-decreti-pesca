# news_normalize_url()

> God node · 6 connections · [C:\Users\giang\Progetti Claude\masaf-decreti-pesca\lib\news_normalize.php](file:///C:/Users/giang/Progetti%20Claude/masaf-decreti-pesca/lib/news_normalize.php#L9)

## Call Trace Diagram

```mermaid
sequenceDiagram
    participant P0 as news_normalize_url()
    participant P1 as bandi_parse_archivio()
    participant P2 as news_item_id()
    participant P3 as bandi_da_feed()
    participant P4 as news_parse_rss()
    participant P5 as news_make_item()
    participant P6 as news_parse_masaf()
    participant P7 as campania_bandi_voci()
    participant P8 as bandi_testo()
    participant P9 as campania_parse_atti()
    participant P10 as bandi_parse_data_italiana()
    participant P11 as bandi_titolo_da_scopo()
    participant P12 as campania_parse_data_numerica()
    participant P13 as news_to_utf8()
    participant P14 as bandi_voce()
    participant P15 as news_clean_summary()
    participant P16 as bandi_regioni_da_classi()
    participant P17 as bandi_e_terminato()
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
    P2->>+ P0: calls
    P0-->>- P2: return
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
    P8->>+ P3: calls
    P3-->>- P8: return
    P8->>+ P9: calls
    P9-->>- P8: return
    P8->>+ P10: calls
    P10-->>- P8: return
    P8->>+ P11: calls
    P11-->>- P8: return
    P8->>+ P12: calls
    P12-->>- P8: return
    P1->>+ P0: calls
    P0-->>- P1: return
    P1->>+ P13: calls
    P13-->>- P1: return
    P1->>+ P14: calls
    P14-->>- P1: return
    P1->>+ P15: calls
    P15-->>- P1: return
    P1->>+ P10: calls
    P10-->>- P1: return
    P1->>+ P11: calls
    P11-->>- P1: return
    P1->>+ P16: calls
    P16-->>- P1: return
    P1->>+ P17: calls
    P17-->>- P1: return
    P0->>+ P2: calls
    P2-->>- P0: return
    P0->>+ P3: calls
    P3-->>- P0: return
    P0->>+ P9: calls
    P9-->>- P0: return
    P0->>+ P5: calls
    P5-->>- P0: return
```

## Connections by Relation

### calls
- [[bandi_parse_archivio()]] `INFERRED`
- [[news_item_id()]] `EXTRACTED`
- [[bandi_da_feed()]] `INFERRED`
- [[campania_parse_atti()]] `INFERRED`
- [[news_make_item()]] `INFERRED`

### contains
- [[news_normalize.php]] `EXTRACTED`

---

*Part of the graphify knowledge wiki. See [[index]] to navigate.*