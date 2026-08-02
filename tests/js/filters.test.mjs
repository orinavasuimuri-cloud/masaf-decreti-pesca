/**
 * Verifica la logica di assets/filters.js pilotandola su un DOM vero.
 *
 * NON fa parte di `php tests/run.php` e non aggiunge dipendenze al progetto:
 * niente package.json, niente lockfile. Va lanciato apposta, dopo aver messo
 * jsdom dove node possa risolverlo risalendo le cartelle (node_modules e'
 * ignorato da git, quindi l'installazione resta locale alla tua macchina):
 *
 *   npm install jsdom          (una volta, dalla radice del progetto)
 *   node tests/js/filters.test.mjs
 *
 * Esiste perche' filters.js e' condiviso dalle tre pagine: una regressione la'
 * dentro le rompe tutte insieme e in silenzio, e tests/test_pagine_markup.php
 * copre il contratto del markup ma non il comportamento del filtro. Le attese
 * sono calcolate scorrendo i blob data-search in modo indipendente, senza
 * riusare la logica sotto test; la sua capacita' di intercettare guasti veri e'
 * stata tarata mutando di proposito filters.js (data-nocount ignorato, ricerca
 * in OR invece che AND, contenitori vuoti mai richiusi: tutte e tre rilevate).
 *
 * Le pagine vengono rese da PHP al volo, cosi' si prova l'HTML davvero servito.
 */
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

// fileURLToPath e non URL.pathname: quest'ultimo lascia gli spazi del percorso
// codificati come %20 e il progetto vive sotto "Progetti Claude".
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const filtersJs = fs.readFileSync(path.join(ROOT, 'assets/filters.js'), 'utf8');
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'filters-test-'));

let pass = 0;
const fails = [];
const ok = (cond, msg) => (cond ? pass++ : fails.push(msg));

const norm = (s) => (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

function rendi(page) {
  const out = path.join(TMP, page + '.html');
  fs.writeFileSync(out, execFileSync('php', [path.join(ROOT, page + '.php')], {
    cwd: ROOT,
    maxBuffer: 64 * 1024 * 1024,
  }));
  return out;
}

function carica(page) {
  const tag = '<script src="assets/filters.js"></script>';
  let html = fs.readFileSync(rendi(page), 'utf8');
  if (!html.includes(tag)) throw new Error(page + ': la pagina non include filters.js');
  html = html.replace(tag, '<script>' + filtersJs + '</script>');
  const dom = new JSDOM(html, { runScripts: 'dangerously', url: 'http://localhost/' + page + '.php' });
  return { win: dom.window, doc: dom.window.document };
}

const visibile = (el) => el.style.display !== 'none';

function digita(win, doc, testo) {
  const q = doc.getElementById('q');
  q.value = testo;
  q.dispatchEvent(new win.Event('input'));
}

function contatore(doc) {
  const m = doc.getElementById('q-status').textContent.match(/^(\d+)\s/);
  return m ? Number(m[1]) : null;
}

/** Quante voci contano come risultato, calcolato senza usare filters.js. */
function atteso(doc, { itemAttr, key, terms }) {
  return [...doc.querySelectorAll('[data-search]')].filter((el) => {
    if (el.hasAttribute('data-nocount')) return false;
    const haChiave = el.hasAttribute(itemAttr);
    if (haChiave && key !== 'all' && el.getAttribute(itemAttr) !== key) return false;
    if (!haChiave && !terms.length) return false; // fuori dal filtro categoriale
    const blob = norm(el.getAttribute('data-search'));
    return terms.every((t) => blob.includes(t));
  }).length;
}

function prova(page, { itemAttr, chipAttr, cerca }) {
  const { win, doc } = carica(page);
  const voci = [...doc.querySelectorAll('[data-search]')];
  const noRes = doc.getElementById('no-results');
  const reset = doc.getElementById('q-clear');

  ok(voci.length > 0, page + ': nessuna voce indicizzata');

  // stato iniziale
  ok(voci.every(visibile), page + ": all'avvio qualche voce e' gia' nascosta");
  ok(noRes.hidden === true, page + ': "nessun risultato" visibile all\'avvio');
  ok(doc.getElementById('q-status').textContent === '', page + ': contatore non vuoto all\'avvio');
  ok(reset.hidden === true, page + ': reset visibile senza ricerca');

  // ricerca senza esiti
  digita(win, doc, 'zqxwvzz');
  ok(voci.every((el) => !visibile(el)), page + ': voci visibili con ricerca senza esiti');
  ok(noRes.hidden === false, page + ': "nessun risultato" non compare');
  ok(contatore(doc) === 0, page + ': contatore ' + contatore(doc) + ' invece di 0');
  ok(reset.hidden === false, page + ': reset nascosto con ricerca attiva');

  // ricerca con esiti
  const terms = norm(cerca).split(/\s+/).filter(Boolean);
  digita(win, doc, cerca);
  const att = atteso(doc, { itemAttr, key: 'all', terms });
  ok(att > 0, page + ': il termine di prova "' + cerca + '" non trova nulla');
  ok(contatore(doc) === att, page + ': contatore ' + contatore(doc) + ' invece di ' + att);
  ok(noRes.hidden === true, page + ': "nessun risultato" mostrato con ' + att + ' esiti');
  ok(
    voci.filter(visibile).every((el) => terms.every((t) => norm(el.getAttribute('data-search')).includes(t))),
    page + ': mostrata una voce che non contiene il termine cercato'
  );

  // reset
  reset.dispatchEvent(new win.Event('click'));
  ok(doc.getElementById('q').value === '', page + ': il reset non svuota il campo');
  ok(voci.every(visibile), page + ': dopo il reset qualche voce resta nascosta');

  // chip categoriale
  const chips = [...doc.querySelectorAll('.yr-chip[' + chipAttr + ']')];
  ok(chips.length > 1, page + ': chip di filtro assenti');
  const chip = chips.find((c) => c.getAttribute(chipAttr) !== 'all');
  if (chip) {
    const key = chip.getAttribute(chipAttr);
    chip.dispatchEvent(new win.Event('click'));
    ok(contatore(doc) === atteso(doc, { itemAttr, key, terms: [] }),
      page + ': il chip "' + key + '" conta ' + contatore(doc));
    ok(chips.filter((c) => c.getAttribute('aria-pressed') === 'true').length === 1,
      page + ': piu\' di un chip premuto insieme');
    ok(voci.filter((el) => visibile(el) && el.hasAttribute(itemAttr) && el.getAttribute(itemAttr) !== key).length === 0,
      page + ': voci fuori dal chip "' + key + '" restano visibili');

    digita(win, doc, cerca);
    ok(contatore(doc) === atteso(doc, { itemAttr, key, terms }), page + ': chip e ricerca insieme contano male');

    chips.find((c) => c.getAttribute(chipAttr) === 'all').dispatchEvent(new win.Event('click'));
    digita(win, doc, '');
    ok(voci.every(visibile), page + ': tornando a "tutti" qualche voce resta nascosta');
    ok(doc.getElementById('q-status').textContent === '', page + ': contatore non vuoto a filtri azzerati');
  }

  // contenitori richiusi quando restano vuoti
  digita(win, doc, 'zqxwvzz');
  const aperti = [...doc.querySelectorAll(
    'section.category, .news-list, .bandi-sec, .bandi-aperti, .pending-box'
  )].filter(visibile);
  ok(aperti.length === 0, page + ': ' + aperti.length + ' contenitori restano aperti pur essendo vuoti');

  // ricerca condivisibile via ?q=
  ok(win.location.search.includes('q=zqxwvzz'), page + ': la ricerca non finisce nell\'indirizzo');
  digita(win, doc, '');
  ok(!win.location.search.includes('q='), page + ': ?q= resta nell\'indirizzo a ricerca svuotata');
}

prova('index', { itemAttr: 'data-year', chipAttr: 'data-yr', cerca: 'pesca' });
prova('news', { itemAttr: 'data-src', chipAttr: 'data-src', cerca: 'pesca' });
prova('bandi', { itemAttr: 'data-stato', chipAttr: 'data-stato', cerca: 'sicilia' });

fs.rmSync(TMP, { recursive: true, force: true });

console.log(pass + ' passati, ' + fails.length + ' falliti');
fails.forEach((f) => console.log('  - ' + f));
process.exit(fails.length ? 1 : 0);
