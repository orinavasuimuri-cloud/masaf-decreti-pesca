# rete_scarica()

> God node · 11 connections · [C:\Users\giang\Progetti Claude\masaf-decreti-pesca\lib\rete.php](file:///C:/Users/giang/Progetti%20Claude/masaf-decreti-pesca/lib/rete.php#L78)

## Call Trace Diagram

```mermaid
sequenceDiagram
    participant P0 as rete_scarica()
    participant P1 as bandi_fetch()
    participant P2 as bandi_totale_api()
    participant P3 as rete_disponibili()
    participant P4 as rete_strategia()
    participant P5 as rete_scarica_curl_ext()
    participant P6 as rete_scarica_https()
    participant P7 as rete_scarica_shell()
    participant P8 as ca_fetch()
    participant P9 as gz_fetch()
    participant P10 as news_fetch_url()
    participant P11 as fetch_url()
    P0->>+ P1: calls
    P1-->>- P0: return
    P1->>+ P0: calls
    P0-->>- P1: return
    P1->>+ P2: calls
    P2-->>- P1: return
    P2->>+ P1: calls
    P1-->>- P2: return
    P0->>+ P3: calls
    P3-->>- P0: return
    P3->>+ P0: calls
    P0-->>- P3: return
    P0->>+ P4: calls
    P4-->>- P0: return
    P4->>+ P0: calls
    P0-->>- P4: return
    P0->>+ P5: calls
    P5-->>- P0: return
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
    P0->>+ P8: calls
    P8-->>- P0: return
    P0->>+ P9: calls
    P9-->>- P0: return
    P0->>+ P10: calls
    P10-->>- P0: return
    P0->>+ P11: calls
    P11-->>- P0: return
```

## Connections by Relation

### calls
- [[bandi_fetch()]] `INFERRED`
- [[rete_disponibili()]] `EXTRACTED`
- [[rete_strategia()]] `EXTRACTED`
- [[rete_scarica_curl_ext()]] `EXTRACTED`
- [[rete_scarica_https()]] `EXTRACTED`
- [[rete_scarica_shell()]] `EXTRACTED`
- [[ca_fetch()]] `INFERRED`
- [[gz_fetch()]] `INFERRED`
- [[news_fetch_url()]] `INFERRED`
- [[fetch_url()]] `INFERRED`

### contains
- [[rete.php]] `EXTRACTED`

---

*Part of the graphify knowledge wiki. See [[index]] to navigate.*