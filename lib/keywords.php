<?php
declare(strict_types=1);

/**
 * Filtro per parole chiave, comune alle tre fonti.
 *
 * Notizie MASAF, bandi regionali e Gazzetta Ufficiale fanno tutte la stessa
 * cosa: prendono un elenco di parole dalla configurazione e tengono le voci in
 * cui almeno una compare. Le tre implementazioni erano copie l'una dell'altra,
 * commento compreso, e il bug che le riguardava - preg_quote() chiamato senza
 * delimitatore - e' stato trovato e corretto tre volte, perche' tre volte era
 * stato scritto.
 *
 * Quel bug e' anche il motivo per cui qui si lancia invece di restituire
 * "nessuna corrispondenza": con un'espressione malformata preg_match()
 * restituisce false, che il chiamante leggerebbe come "questa voce non e' in
 * tema". Tutte le voci verrebbero scartate e la fonte sembrerebbe soltanto
 * povera di notizie. Un filtro rotto deve fermare la raccolta, non svuotarla in
 * silenzio.
 */

/**
 * Costruisce l'espressione che riconosce una qualunque delle parole date.
 *
 * Restituisce la stringa vuota quando non c'e' nulla da filtrare: e' il segnale
 * che il chiamante deve lasciar passare tutto, ed e' anche il motivo per cui la
 * validazione sta qui e non nel ciclo. Con un elenco vuoto, implode() darebbe
 * '//iu', un'espressione che corrisponde ovunque - stesso esito, ma per caso
 * invece che per scelta.
 *
 * Il delimitatore va passato a preg_quote(): le parole arrivano dalla
 * configurazione, non dal codice, e una che contenga una barra renderebbe
 * l'espressione malformata.
 *
 * Si valida una volta qui, contro la stringa vuota, invece che a ogni voce: il
 * pattern non cambia durante la raccolta, e un errore va scoperto prima di
 * scorrere l'elenco, non a meta'.
 *
 * @param list<string> $parole
 * @throws RuntimeException se le parole producono un'espressione non valida
 */
function keywords_pattern(array $parole): string
{
    if ($parole === []) {
        return '';
    }
    $pattern = '/' . implode('|', array_map(
        static fn(string $p): string => preg_quote($p, '/'),
        $parole
    )) . '/iu';

    if (@preg_match($pattern, '') === false) {
        throw new RuntimeException("Espressione delle parole chiave non valida: $pattern");
    }
    return $pattern;
}

/**
 * Vero se il testo contiene una delle parole del pattern.
 *
 * Un pattern vuoto lascia passare tutto: chi non ha configurato parole chiave
 * vuole l'intera fonte, non zero voci.
 */
function keywords_corrisponde(string $pattern, string $testo): bool
{
    if ($pattern === '') {
        return true;
    }
    return preg_match($pattern, $testo) === 1;
}
