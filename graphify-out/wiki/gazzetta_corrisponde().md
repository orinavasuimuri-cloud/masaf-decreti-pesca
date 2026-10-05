# gazzetta_corrisponde()

> God node · 5 connections · [C:\Users\giang\Progetti Claude\masaf-decreti-pesca\lib\gazzetta_parser.php](file:///C:/Users/giang/Progetti%20Claude/masaf-decreti-pesca/lib/gazzetta_parser.php#L333)

## Call Trace Diagram

```mermaid
sequenceDiagram
    participant P0 as gazzetta_corrisponde()
    participant P1 as keywords_corrisponde()
    participant P2 as bandi_da_feed()
    participant P3 as news_item_id()
    participant P4 as bandi_testo()
    participant P5 as news_normalize_url()
    participant P6 as bandi_voce()
    participant P7 as keywords_pattern()
    participant P8 as news_clean_summary()
    participant P9 as news_parse_masaf()
    participant P10 as news_make_item()
    participant P11 as news_to_utf8()
    participant P12 as gazzetta_in_tema()
    participant P13 as gazzetta_destinazione()
    P0->>+ P1: calls
    P1-->>- P0: return
    P1->>+ P2: calls
    P2-->>- P1: return
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
    P2->>+ P1: calls
    P1-->>- P2: return
    P2->>+ P8: calls
    P8-->>- P2: return
    P1->>+ P9: calls
    P9-->>- P1: return
    P9->>+ P3: calls
    P3-->>- P9: return
    P9->>+ P10: calls
    P10-->>- P9: return
    P9->>+ P11: calls
    P11-->>- P9: return
    P9->>+ P7: calls
    P7-->>- P9: return
    P9->>+ P1: calls
    P1-->>- P9: return
    P1->>+ P0: calls
    P0-->>- P1: return
    P0->>+ P7: calls
    P7-->>- P0: return
    P0->>+ P12: calls
    P12-->>- P0: return
    P0->>+ P13: calls
    P13-->>- P0: return
```

## Connections by Relation

### calls
- [[keywords_corrisponde()]] `INFERRED`
- [[keywords_pattern()]] `INFERRED`
- [[gazzetta_in_tema()]] `EXTRACTED`
- [[gazzetta_destinazione()]] `EXTRACTED`

### contains
- [[gazzetta_parser.php]] `EXTRACTED`

---

*Part of the graphify knowledge wiki. See [[index]] to navigate.*