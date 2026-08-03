<?php
declare(strict_types=1);

/**
 * Campo di ricerca condiviso da registro.php, news.php e bandi.php.
 *
 * Gli id emessi qui (#q, #q-clear, #q-status, #no-results) sono il contratto
 * con assets/filters.js: rinominarli qui significa rinominarli anche là.
 * htmlspecialchars() invece di h(): quella è definita nelle pagine, non qui.
 */
function render_searchbar(string $placeholder, string $label = 'Cerca in questa pagina'): void
{
    $ph = htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8');
    $lb = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
  <div class="searchbar">
    <label class="sr-only" for="q">{$lb}</label>
    <svg class="search-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M16.5 16.5 21 21"/></svg>
    <input type="search" id="q" autocomplete="off" placeholder="{$ph}">
    <button type="button" class="search-clear" id="q-clear" hidden aria-label="Cancella la ricerca">✕</button>
    <p class="search-status" id="q-status" role="status" aria-live="polite"></p>
  </div>

HTML;
}

/**
 * Riquadro mostrato quando ricerca e chip non lasciano alcuna voce visibile.
 * Va emesso dopo i chip, subito prima dei contenuti filtrabili.
 */
function render_no_results(string $testo = 'Nessuna voce corrisponde ai filtri attivi.'): void
{
    $t = htmlspecialchars($testo, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
  <div class="no-results" id="no-results" hidden>{$t}</div>

HTML;
}
