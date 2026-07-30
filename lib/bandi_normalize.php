<?php
declare(strict_types=1);

/**
 * Funzioni pure per i bandi. Nessun accesso a rete o disco: sono la base
 * testabile su cui poggiano parser, store e pagina.
 */

/**
 * Testo leggibile da un frammento HTML. Oltre a tag ed entità normalizza lo
 * spazio unificatore U+00A0, che nell'archivio separa l'etichetta dal valore:
 * senza questa sostituzione le date non sono interpretabili.
 */
function bandi_testo(string $html): string {
    $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = (string) preg_replace('/[\x{00A0}\s]+/u', ' ', $t);
    return trim($t);
}

/**
 * "15 Luglio 2026" -> "2026-07-15". Senza intl la mappa dei mesi è scritta a
 * mano; i nomi italiani sono ASCII puri, quindi strtolower basta.
 * Restituisce null su input non interpretabile: chi chiama decide il fallback.
 */
function bandi_parse_data_italiana(string $raw): ?string {
    $raw = bandi_testo($raw);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/u', $raw, $m) !== 1) {
        return null;
    }
    $mesi = [
        'gennaio' => 1, 'febbraio' => 2, 'marzo' => 3, 'aprile' => 4,
        'maggio' => 5, 'giugno' => 6, 'luglio' => 7, 'agosto' => 8,
        'settembre' => 9, 'ottobre' => 10, 'novembre' => 11, 'dicembre' => 12,
    ];
    $mese = $mesi[strtolower($m[2])] ?? null;
    if ($mese === null) {
        return null;
    }
    $giorno = (int) $m[1];
    $anno = (int) $m[3];
    if (!checkdate($mese, $giorno, $anno)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $anno, $mese, $giorno);
}

/**
 * Lo stato non si copia dalla fonte: si calcola. La categoria "Terminato"
 * dell'aggregatore arriva con giorni di ritardo, quindi decide solo quando la
 * scadenza manca. Le date sono ISO, il confronto fra stringhe è corretto.
 */
function bandi_stato(?string $scadenza, bool $terminatoInFonte, string $oggi): string {
    if ($scadenza !== null && $scadenza !== '') {
        return $scadenza >= $oggi ? 'aperto' : 'chiuso';
    }
    return $terminatoInFonte ? 'chiuso' : 'da_verificare';
}

/**
 * Sull'aggregatore il titolo del post è la priorità FEAMPA, uguale per decine
 * di bandi. Il titolo utile sta nello scopo: quando c'è, è il nome dell'azione
 * fra virgolette; altrimenti si tronca l'inizio della prima frase.
 */
function bandi_titolo_da_scopo(string $scopo, int $max = 90): string {
    $s = bandi_testo($scopo);
    if ($s === '') {
        return '';
    }
    if (preg_match('/[«""„](.{10,120}?)[»"""]/u', $s, $m) === 1) {
        return trim($m[1]);
    }
    if (strlen($s) <= $max) {
        return $s;
    }
    if (preg_match('/^(.{20,' . $max . '})(?=[\s.,;:])/u', $s, $m) === 1) {
        return rtrim($m[1], " ,.;:") . '…';
    }
    return $s;
}

/**
 * Le categorie stanno nelle classi dell'<article>: "category-terminato
 * category-toscana". Un post può portarne più d'una, quindi si restituisce una
 * lista: la voce comparirà nella sezione di ogni regione indicata.
 */
function bandi_regioni_da_classi(string $classAttr): array {
    if (preg_match_all('/\bcategory-([a-z0-9\-]+)\b/', $classAttr, $m) === 0) {
        return [];
    }
    $slug = [];
    foreach ($m[1] as $s) {
        if ($s === 'terminato' || $s === 'senza-categoria') {
            continue;
        }
        $slug[$s] = true;
    }
    return array_keys($slug);
}

function bandi_e_terminato(string $classAttr): bool {
    return preg_match('/\bcategory-terminato\b/', $classAttr) === 1;
}
