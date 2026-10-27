<?php
declare(strict_types=1);

/**
 * Bottone "Aggiorna" e banner di conferma, condivisi da index.php, bandi.php e
 * news.php. Il bottone è unico per tutto il sito: da qualunque pagina parta,
 * aggiorna tutti i dati (vedi lib/aggiorna_config.php e aggiorna_tutto.php).
 *
 * Le due parti si chiamano separatamente perché vivono in punti diversi della
 * pagina: il bottone sta dentro .meta-row, in riga con le altre cifre di
 * testata; il banner resta fuori, perché è un blocco a piena larghezza e dentro
 * una riga flex si schiaccerebbe.
 *
 * $pagina dice solo dove tornare dopo il clic, e deve combaciare con le chiavi
 * di lib/aggiorna_config.php.
 */
function render_refresh_button(string $pagina): void
{
    $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    ?>
    <form class="refresh-form" method="post" action="aggiorna.php">
      <input type="hidden" name="pagina" value="<?= $h($pagina) ?>">
      <button type="submit" class="refresh-btn" title="Aggiorna decreti, Gazzetta Ufficiale, notizie e bandi">↻ Aggiorna tutti i dati</button>
    </form>
    <?php
}

function render_refresh_banner(string $pagina): void
{
    if (($_GET['aggiornato'] ?? '') !== $pagina) {
        return;
    }
    echo '<div class="refresh-ok">✓ Aggiornamento avviato in background per tutte le pagine: '
        . 'prima notizie e decreti (pochi minuti), per ultimi i bandi, che richiedono più tempo. '
        . 'Ricarica la pagina per vedere i dati nuovi.</div>';
}
