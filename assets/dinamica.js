// Movimento condiviso da news.php e index.php: schede che entrano mentre si
// scorre la pagina e numeri del riepilogo che contano fino al valore.
(function () {
  var fermo = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  if (fermo) return;

  if ("IntersectionObserver" in window) {
    var obs = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        e.target.classList.add("visibile");
        obs.unobserve(e.target);
      });
    }, { rootMargin: "0px 0px -40px 0px" });
    document.documentElement.classList.add("anima");
    document.querySelectorAll(".rivela").forEach(function (el, i) {
      el.style.setProperty("--ritardo", (i % 6) * 60 + "ms");
      obs.observe(el);
    });
    // Le schede dentro una sezione richiusa non sono mai state nella finestra:
    // all'apertura si mostrano subito, senza aspettare l'osservatore.
    document.addEventListener("toggle", function (e) {
      if (!e.target.open) return;
      e.target.querySelectorAll(".rivela:not(.visibile)").forEach(function (el) {
        el.classList.add("visibile");
        obs.unobserve(el);
      });
    }, true);
  }

  document.querySelectorAll("[data-conta]").forEach(function (el) {
    var fine = parseInt(el.getAttribute("data-conta"), 10);
    if (!(fine > 0)) return;
    var inizio = null, durata = 900;
    function passo(t) {
      if (inizio === null) inizio = t;
      var p = Math.min(1, (t - inizio) / durata);
      el.textContent = Math.round(fine * (1 - Math.pow(1 - p, 3)));
      if (p < 1) requestAnimationFrame(passo);
    }
    el.textContent = "0";
    requestAnimationFrame(passo);
  });
})();
