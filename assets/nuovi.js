/*
 * Decreti nuovi non ancora visti, segnalati sulla riga della sezione chiusa.
 *
 * Una scheda è "nuova" per 60 giorni (data-nuovo, messo da index.php). La riga
 * della sezione mostra "● N nuovi" lampeggiante finché quei decreti non sono
 * stati visti: aprire la sezione li segna come visti in questo browser
 * (localStorage), e il segnale torna solo quando ne esce un altro.
 * Senza localStorage (navigazione privata) il segnale resta per tutti i nuovi.
 */
(function (global) {
  var CHIAVE = "decreti-visti";

  function leggi() {
    try {
      var v = JSON.parse(global.localStorage.getItem(CHIAVE) || "[]");
      return Array.isArray(v) ? v : [];
    } catch (e) { return []; }
  }
  function salva(lista) {
    try { global.localStorage.setItem(CHIAVE, JSON.stringify(lista.slice(-500))); } catch (e) { /* non si ricorda, pazienza */ }
  }

  global.initNuovi = function () {
    var doc = global.document;
    var visti = leggi();
    doc.querySelectorAll("details.category").forEach(function (sez) {
      var badge = sez.querySelector(":scope > summary .sez-nuovi");
      if (!badge) { return; }
      var chip = sez.id ? doc.querySelector('.index a.chip[href="#' + sez.id + '"]') : null;
      var chiavi = Array.prototype.map.call(sez.querySelectorAll("[data-nuovo]"), function (c) {
        return c.getAttribute("data-nuovo");
      });
      function aggiorna() {
        var n = chiavi.filter(function (k) { return visti.indexOf(k) === -1; }).length;
        badge.hidden = n === 0;
        badge.textContent = n === 1 ? "1 nuovo" : n + " nuovi";
        badge.title = n === 1 ? "1 decreto nuovo non ancora visto" : n + " decreti nuovi non ancora visti";
        if (chip) { chip.classList.toggle("ha-nuovi", n > 0); }
      }
      aggiorna();
      sez.addEventListener("toggle", function () {
        if (!sez.open) { return; }
        chiavi.forEach(function (k) { if (visti.indexOf(k) === -1) { visti.push(k); } });
        salva(visti);
        aggiorna();
      });
    });
  };
})(window);
