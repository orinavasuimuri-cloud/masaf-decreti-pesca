/**
 * Ricerca testuale + filtro a chip, condiviso da index.php, news.php e bandi.php.
 *
 * Convenzioni del markup, uguali sulle tre pagine:
 *  - ogni voce filtrabile porta data-search="<testo indicizzato>";
 *  - la stessa voce può portare l'attributo categoriale della pagina
 *    (data-year, data-src, data-stato). Se l'attributo manca, i chip non la
 *    nascondono: è il caso degli atti da rivedere in index.php, che non hanno
 *    un anno, e delle righe "in scadenza" di bandi.php, sempre aperte;
 *  - data-nocount tiene una voce fuori dal totale mostrato. Serve per i
 *    duplicati: il riquadro "in scadenza" ripete bandi già contati sotto.
 *
 * Gli id del campo di ricerca (#q, #q-clear, #q-status, #no-results) sono
 * fissi: le tre pagine includono lo stesso frammento di markup.
 */
(function (global) {
  "use strict";

  // Confronto senza accenti né maiuscole: "gia" trova "già", "PESCA" trova "pesca".
  function norm(s) {
    return (s || "").toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
  }

  function all(selectors) {
    var out = [];
    (selectors || []).forEach(function (sel) {
      out = out.concat(Array.prototype.slice.call(document.querySelectorAll(sel)));
    });
    return out;
  }

  /**
   * opts:
   *   chipAttr    attributo dei bottoni .yr-chip (es. "data-yr")
   *   itemAttr    attributo corrispondente sulle voci (es. "data-year"); omesso
   *               se la pagina non ha un filtro a chip
   *   containers  selettori dei blocchi da nascondere quando restano senza voci
   *   firstVisible selettore dei blocchi che portano un bordo di separazione:
   *               il primo ancora visibile riceve la classe "first-visible"
   *   labels      { one, many } per il contatore
   */
  global.initFilters = function (opts) {
    var input = document.getElementById("q");
    var clearBtn = document.getElementById("q-clear");
    var statusEl = document.getElementById("q-status");
    var noResults = document.getElementById("no-results");
    var chips = opts.chipAttr
      ? document.querySelectorAll(".yr-chip[" + opts.chipAttr + "]")
      : [];
    var containers = all(opts.containers);
    var striped = all(opts.firstVisible ? [opts.firstVisible] : []);
    var labels = opts.labels || { one: "voce trovata", many: "voci trovate" };
    var current = "all";

    // I blob sono normalizzati una volta sola, non a ogni tasto premuto.
    var records = Array.prototype.map.call(
      document.querySelectorAll("[data-search]"),
      function (el) {
        // hasAttribute e non getAttribute: una chiave presente ma vuota
        // (data-src="" per una notizia senza fonte) va trattata come una
        // chiave che non corrisponde a nessun chip, non come chiave assente.
        var hasKey = opts.itemAttr && el.hasAttribute(opts.itemAttr);
        return {
          el: el,
          blob: norm(el.getAttribute("data-search")),
          key: hasKey ? el.getAttribute(opts.itemAttr) : null,
          counts: !el.hasAttribute("data-nocount")
        };
      }
    );

    function anyVisible(container) {
      var tracked = container.querySelectorAll("[data-search]");
      if (!tracked.length) return true;
      return Array.prototype.some.call(tracked, function (c) {
        return c.style.display !== "none";
      });
    }

    function apply() {
      var raw = input ? input.value.trim() : "";
      var terms = norm(raw).split(/\s+/).filter(Boolean);
      var visible = 0;

      records.forEach(function (r) {
        var okKey = r.key === null || current === "all" || r.key === current;
        var okText = terms.every(function (t) { return r.blob.indexOf(t) !== -1; });
        var show = okKey && okText;
        r.el.style.display = show ? "" : "none";
        // Una voce senza chiave resta visibile con un chip attivo, ma non va
        // contata come risultato di quel filtro: gonfierebbe il totale e
        // impedirebbe al messaggio "nessun risultato" di comparire.
        if (show && r.counts && (r.key !== null || terms.length)) visible++;
      });

      containers.forEach(function (c) {
        c.style.display = anyVisible(c) ? "" : "none";
      });

      // Il bordo di separazione va tolto al primo blocco ancora visibile,
      // altrimenti raddoppia quello del contenitore quando i precedenti
      // sono stati filtrati via.
      var seen = false;
      striped.forEach(function (el) {
        var shown = el.style.display !== "none";
        el.classList.toggle("first-visible", shown && !seen);
        if (shown) seen = true;
      });

      if (noResults) noResults.hidden = visible > 0;
      if (clearBtn) clearBtn.hidden = raw === "";
      if (statusEl) {
        statusEl.textContent = (raw === "" && current === "all")
          ? ""
          : visible + " " + (visible === 1 ? labels.one : labels.many);
      }

      // Su file:// (o con URL non parsabili) replaceState può lanciare: la
      // ricerca deve continuare a funzionare anche senza aggiornare l'indirizzo.
      try {
        var url = new URL(global.location.href);
        if (raw) { url.searchParams.set("q", raw); } else { url.searchParams.delete("q"); }
        global.history.replaceState(null, "", url);
      } catch (e) { /* nessuna sincronizzazione dell'URL */ }
    }

    Array.prototype.forEach.call(chips, function (btn) {
      btn.addEventListener("click", function () {
        Array.prototype.forEach.call(chips, function (b) {
          b.setAttribute("aria-pressed", "false");
        });
        btn.setAttribute("aria-pressed", "true");
        current = btn.getAttribute(opts.chipAttr);
        apply();
      });
    });

    if (input) {
      input.addEventListener("input", apply);
      input.addEventListener("keydown", function (e) {
        if (e.key === "Escape") { input.value = ""; apply(); }
      });
    }
    if (clearBtn && input) {
      clearBtn.addEventListener("click", function () {
        input.value = "";
        input.focus();
        apply();
      });
    }

    // Ricerca condivisibile via ?q=... nell'indirizzo
    try {
      var initial = new URL(global.location.href).searchParams.get("q");
      if (initial && input) { input.value = initial; }
    } catch (e) { /* si parte senza ricerca precompilata */ }

    apply();
  };
})(window);
