<?php
declare(strict_types=1);

/**
 * L'indice MASAF da cui legge scraper.php riporta solo l'oggetto dell'atto
 * ("Disposizioni in materia di..."), senza numero né data: chi cercava il
 * D.D. 614745 non trovava nulla, pur avendo l'atto in pagina. Il titolo
 * completo sta solo nella scheda dell'atto, nel meta og:title.
 */

/** Titolo completo dalla scheda MASAF, già in UTF-8; null se la scheda non lo dichiara. */
function scheda_masaf_titolo(string $html): ?string
{
    // Le schede MASAF sono in ISO-8859-1, ma non lo si dà per scontato.
    if (preg_match('//u', $html) !== 1) {
        $convertito = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $html);
        $html = $convertito === false ? '' : $convertito;
    }
    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)/i', $html, $m) !== 1
        && preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title/i', $html, $m) !== 1) {
        return null;
    }
    $titolo = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $titolo = trim((string) preg_replace(['/^\s*Masaf\s*-\s*/iu', '/\s+/u'], ['', ' '], $titolo));
    return $titolo === '' ? null : $titolo;
}

/**
 * Estremi dell'atto in testa al titolo, nella forma usata dal catalogo
 * ("D.D. n. 614745 · 09/11/2026"); null se il titolo non comincia con un atto
 * numerato (avvisi, comunicazioni, pagine informative).
 */
function scheda_masaf_ref(string $titolo): ?string
{
    // Non ancorata all'inizio: molti titoli cominciano con "FEAMPA 2021/2027 ...".
    $mesi = 'gennaio|febbraio|marzo|aprile|maggio|giugno|luglio|agosto|settembre|ottobre|novembre|dicembre';
    $re = '/\b(D\.?\s*[DM]\.?|Decreto(?:\s+(?:direttoriale|ministeriale|interministeriale))?)\s*(?:prot\.?\s*)?n\.?\s*°?\s*(\d[\d.]*)'
        . '\s+del\s+(\d{1,2})(?:\/(\d{1,2})\/|\s+(' . $mesi . ')\s+)(\d{4})/iu';
    if (preg_match($re, $titolo, $m) !== 1) {
        return null;
    }
    $mese = $m[4] !== ''
        ? (int) $m[4]
        : array_search(strtolower($m[5]), explode('|', $mesi), true) + 1;
    $tipo = preg_match('/^D\.?\s*M|ministeriale/i', $m[1]) === 1 ? 'D.M.' : 'D.D.';
    return sprintf('%s n. %s · %02d/%02d/%s', $tipo, str_replace('.', '', $m[2]), (int) $m[3], $mese, $m[6]);
}
