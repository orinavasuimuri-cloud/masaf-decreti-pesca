<?php
declare(strict_types=1);

/**
 * Bottone "Aggiorna ora" e banner di conferma, condivisi da index.php,
 * bandi.php e news.php: stessa forma su tutte e tre, per non ripetere a mano
 * la stessa logica in tre punti - lo stesso motivo per cui lib/keywords.php
 * esiste invece di restare copiato in tre parser.
 *
 * Le due parti si chiamano separatamente perché vivono in punti diversi della
 * pagina: il bottone sta dentro .meta-row, in riga con le altre cifre di
 * testata, per non aprire una riga tutta sua; il banner di conferma resta
 * fuori, perché è un blocco a piena larghezza e dentro una riga flex si
 * schiaccerebbe.
 *
 * $pagina è la chiave che aggiorna.php usa per sapere quali fetcher lanciare
 * e dove reindirizzare al termine; deve combaciare con le chiavi di
 * lib/aggiorna_config.php.
 */
function render_refresh_button(string $pagina, string $etichetta): void
{
    $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    ?>
    <form class="refresh-form" method="post" action="aggiorna.php">
      <input type="hidden" name="pagina" value="<?= $h($pagina) ?>">
      <button type="submit" class="refresh-btn">↻ Aggiorna <?= $h($etichetta) ?> ora</button>
    </form>
    <?php
}

function render_refresh_banner(string $pagina, string $etichetta): void
{
    if (($_GET['aggiornato'] ?? '') !== $pagina) {
        return;
    }
    $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    echo '<div class="refresh-ok">✓ Aggiornamento avviato in background per ' . $h($etichetta) . ': '
        . 'i nuovi dati compaiono in pagina quando il fetcher ha finito, di solito entro qualche minuto. '
        . 'Ricarica per controllare.</div>';
}
