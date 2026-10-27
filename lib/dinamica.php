<?php
declare(strict_types=1);

/** Primo dd/mm/yyyy nel testo, come timestamp; null se non c'è o non è valido. */
function dinamica_data_da_testo(string $testo): ?int
{
    if (preg_match('#\b(\d{2})/(\d{2})/(\d{4})\b#', $testo, $m) !== 1 || !checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
        return null;
    }
    return mktime(0, 0, 0, (int) $m[2], (int) $m[1], (int) $m[3]);
}

/**
 * Striscia di titoli che scorre in orizzontale (CSS in assets/dinamica.css).
 * L'elenco è stampato due volte: l'animazione sposta di metà larghezza, così il
 * ritorno all'inizio non si vede. La seconda copia è nascosta agli screen reader
 * e fuori dalla tabulazione.
 *
 * @param list<array{src: string, title: string, url: ?string}> $voci
 */
function dinamica_ticker(array $voci, string $etichetta): void
{
    if ($voci === []) {
        return;
    }
    $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $durata = max(40, count($voci) * 7);
    echo '<div class="ticker" aria-label="' . $e($etichetta) . '" style="--ticker-durata: ' . $durata . 's">';
    echo '<span class="ticker-label">' . $e($etichetta) . '</span><div class="ticker-viewport"><div class="ticker-track">';
    foreach ([false, true] as $copia) {
        echo $copia ? '<ul aria-hidden="true">' : '<ul>';
        foreach ($voci as $v) {
            $url = $v['url'] ?? null;
            $sicuro = is_string($url) && (stripos($url, 'http://') === 0 || stripos($url, 'https://') === 0);
            echo '<li><span class="ticker-src">' . $e($v['src']) . '</span>';
            echo $sicuro
                ? '<a href="' . $e($url) . '" target="_blank" rel="noopener"' . ($copia ? ' tabindex="-1"' : '') . '>' . $e($v['title']) . '</a>'
                : $e($v['title']);
            echo '</li>';
        }
        echo '</ul>';
    }
    echo '</div></div></div>';
}
