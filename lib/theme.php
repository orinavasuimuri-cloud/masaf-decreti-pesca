<?php
declare(strict_types=1);

/**
 * Temi commutabili, condivisi da registro.php, news.php e bandi.php.
 *
 * La scelta vive in localStorage, non lato server: le pagine sono statiche
 * rispetto all'utente (nessuna sessione, nessun cookie da dichiarare) e il
 * tema è una preferenza di sola presentazione.
 *
 * L'anteprima affiancata dei temi sta in temi.php; le regole in
 * assets/themes.css.
 */

const THEMES = [
    'default'  => 'Originale',
    'laguna'   => 'Laguna',
    'nautica'  => 'Carta nautica',
    'gazzetta' => 'Gazzetta',
    'molo'     => 'Molo',
];

/**
 * Va dentro <head>, dopo il link a style.css.
 *
 * Lo script è inline e sincrono di proposito: un file esterno verrebbe
 * eseguito dopo il primo disegno della pagina e si vedrebbe il tema
 * originale lampeggiare prima di quello scelto.
 */
function render_theme_head(): void
{
    $valid = json_encode(array_keys(THEMES), JSON_UNESCAPED_UNICODE);
    echo <<<HTML
<link rel="stylesheet" href="assets/themes.css">
<script>
(function () {
  try {
    var t = localStorage.getItem('tema');
    if (t && t !== 'default' && {$valid}.indexOf(t) !== -1) {
      document.documentElement.setAttribute('data-theme', t);
    }
  } catch (e) { /* localStorage negato (navigazione privata): resta l'originale */ }
})();
</script>

HTML;
}

/**
 * Selettore da mettere nella topbar. Il <select> è già allineato al tema
 * attivo dallo script, che gira prima che questo markup sia analizzato:
 * l'attributo selected non lo si può stampare da PHP, il server non sa
 * quale tema abbia scelto il visitatore.
 */
function render_theme_switcher(): void
{
    $opts = '';
    foreach (THEMES as $id => $label) {
        $i = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        $l = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $opts .= "<option value=\"{$i}\">{$l}</option>";
    }
    echo <<<HTML
<span class="theme-pick">
  <label for="theme-select">Tema</label>
  <select id="theme-select">{$opts}</select>
  <a href="temi.php">anteprima</a>
</span>
<script>
(function () {
  var sel = document.getElementById('theme-select');
  var cur = document.documentElement.getAttribute('data-theme') || 'default';
  sel.value = cur;
  sel.addEventListener('change', function () {
    var t = sel.value;
    if (t === 'default') { document.documentElement.removeAttribute('data-theme'); }
    else { document.documentElement.setAttribute('data-theme', t); }
    try { localStorage.setItem('tema', t); } catch (e) { /* preferenza non memorizzabile */ }
  });
})();
</script>

HTML;
}
