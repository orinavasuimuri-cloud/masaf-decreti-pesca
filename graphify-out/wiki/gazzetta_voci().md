# gazzetta_voci()

> God node · 5 connections · [C:\Users\giang\Progetti Claude\masaf-decreti-pesca\lib\gazzetta_parser.php](file:///C:/Users/giang/Progetti%20Claude/masaf-decreti-pesca/lib/gazzetta_parser.php#L395)

## Call Trace Diagram

```mermaid
sequenceDiagram
    participant P0 as gazzetta_voci()
    participant P1 as gazzetta_in_tema()
    participant P2 as gazzetta_corrisponde()
    participant P3 as keywords_corrisponde()
    participant P4 as keywords_pattern()
    participant P5 as gazzetta_destinazione()
    participant P6 as gazzetta_codice_atto()
    participant P7 as gazzetta_scompone_titolo()
    P0->>+ P1: calls
    P1-->>- P0: return
    P1->>+ P2: calls
    P2-->>- P1: return
    P2->>+ P3: calls
    P3-->>- P2: return
    P2->>+ P4: calls
    P4-->>- P2: return
    P2->>+ P1: calls
    P1-->>- P2: return
    P2->>+ P5: calls
    P5-->>- P2: return
    P1->>+ P0: calls
    P0-->>- P1: return
    P0->>+ P5: calls
    P5-->>- P0: return
    P5->>+ P2: calls
    P2-->>- P5: return
    P5->>+ P0: calls
    P0-->>- P5: return
    P0->>+ P6: calls
    P6-->>- P0: return
    P6->>+ P0: calls
    P0-->>- P6: return
    P0->>+ P7: calls
    P7-->>- P0: return
    P7->>+ P0: calls
    P0-->>- P7: return
```

## Connections by Relation

### calls
- [[gazzetta_in_tema()]] `EXTRACTED`
- [[gazzetta_destinazione()]] `EXTRACTED`
- [[gazzetta_codice_atto()]] `EXTRACTED`
- [[gazzetta_scompone_titolo()]] `EXTRACTED`

### contains
- [[gazzetta_parser.php]] `EXTRACTED`

---

*Part of the graphify knowledge wiki. See [[index]] to navigate.*