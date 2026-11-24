/**
 * assets/nuovi.js su index.php reso da PHP: "N nuovi" sulle sezioni chiuse,
 * spento all'apertura, ricordato fra un caricamento e l'altro, riacceso da un
 * decreto nuovo arrivato dopo. Uso: node tests/js/nuovi.test.mjs (serve jsdom,
 * vedi filters.test.mjs).
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const js = fs.readFileSync(path.join(ROOT, 'assets/nuovi.js'), 'utf8');
const html = execFileSync('php', [path.join(ROOT, 'index.php')], { cwd: ROOT, encoding: 'utf8' });

let pass = 0;
const fails = [];
const ok = (c, m) => (c ? pass++ : fails.push(m));

function carica(storage, pagina = html) {
  const dom = new JSDOM(pagina, { url: 'https://decreti.example/index.php', runScripts: 'outside-only' });
  const w = dom.window;
  for (const [k, v] of Object.entries(storage)) w.localStorage.setItem(k, v);
  w.eval(js);
  w.initNuovi();
  return w;
}
const ricorda = (w) => ({ 'decreti-visti': w.localStorage.getItem('decreti-visti') || '[]' });

let w = carica({});
const sezioni = [...w.document.querySelectorAll('details.category')].filter((d) => d.querySelector(':scope > summary .sez-nuovi'));
const conNuovi = sezioni.filter((d) => d.querySelectorAll('[data-nuovo]').length > 0);
ok(conNuovi.length > 0, 'nessuna sezione ha decreti nuovi: il test non prova nulla');
for (const d of sezioni) {
  const n = d.querySelectorAll('[data-nuovo]').length;
  const b = d.querySelector('.sez-nuovi');
  ok(b.hidden === (n === 0), `${d.id}: etichetta ${b.hidden ? 'nascosta' : 'visibile'} con ${n} nuovi`);
  if (n > 0) ok(b.textContent === (n === 1 ? '1 nuovo' : `${n} nuovi`), `${d.id}: testo "${b.textContent}" invece di ${n}`);
  const chip = w.document.querySelector(`.index a.chip[href="#${d.id}"]`);
  if (chip) ok(chip.classList.contains('ha-nuovi') === (n > 0), `${d.id}: pallino dell'indice sbagliato`);
}

// Aprire la prima sezione con nuovi la spegne, le altre restano accese.
const prima = conNuovi[0];
prima.open = true;
prima.dispatchEvent(new w.Event('toggle'));
ok(prima.querySelector('.sez-nuovi').hidden, `${prima.id}: dopo l'apertura l'etichetta resta`);
if (conNuovi[1]) ok(!conNuovi[1].querySelector('.sez-nuovi').hidden, `${conNuovi[1].id}: spenta senza essere aperta`);

// Ricaricando la pagina resta spenta.
const mem = ricorda(w);
w = carica(mem);
ok(w.document.getElementById(prima.id).querySelector('.sez-nuovi').hidden, `${prima.id}: dopo il ricaricamento torna accesa`);

// Un decreto nuovo arrivato dopo la riaccende con 1.
const finto = html.replace('data-nuovo="', 'data-nuovo="decreto-arrivato-dopo"></article><article data-x="');
w = carica(mem, finto);
const primaSez = [...w.document.querySelectorAll('details.category')].find((d) => d.querySelector('[data-nuovo="decreto-arrivato-dopo"]'));
ok(primaSez && primaSez.id === prima.id, 'il decreto finto non è finito nella sezione già vista');
const b2 = primaSez && primaSez.querySelector('.sez-nuovi');
ok(b2 && !b2.hidden && b2.textContent === '1 nuovo', `un decreto arrivato dopo deve dare "1 nuovo", dà "${b2 && b2.textContent}"`);

console.log(`${pass} passati, ${fails.length} falliti`);
fails.forEach((f) => console.log('  FALLITO: ' + f));
process.exit(fails.length ? 1 : 0);
