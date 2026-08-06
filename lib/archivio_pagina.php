<?php
declare(strict_types=1);

/**
 * Caricamento degli archivi JSON dal lato pagina.
 *
 * I fetcher usano gli store (lib/bandi_store.php, lib/news_store.php,
 * lib/gazzetta_store.php), che su archivio corrotto lanciano: devono fermarsi
 * prima di riscrivere un file buono partendo dal nulla. Una pagina non può
 * permetterselo - un guasto dei dati può fermare la raccolta, mai il sito - ma
 * nemmeno può tacere: degradare a "vuoto" senza dirlo mostra al curatore una
 * coda vuota, che somiglia in tutto a "non c'è niente da fare".
 *
 * Il punto non è quindi scegliere fra rompersi e tacere, ma distinguere i due
 * casi che finora si confondevano: il file che non c'è ancora - prima
 * installazione, prima esecuzione, stato iniziale legittimo - e il file che
 * c'è ma non si legge.
 */

/**
 * Restituisce l'archivio, o $vuoto se il file manca o è illeggibile.
 *
 * Il nome del file finisce in $guasti solo nel secondo caso, così la pagina
 * può dichiarare l'avaria senza confonderla con la prima esecuzione.
 *
 * @param array $vuoto  la forma da restituire quando non c'è nulla da leggere
 * @param list<string> $guasti  accumulatore, per elencare tutti i guasti insieme
 */
function archivio_pagina(string $path, array $vuoto, array &$guasti): array
{
    if (!file_exists($path)) {
        return $vuoto;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        $guasti[] = basename($path);
        return $vuoto;
    }
    $dati = json_decode($raw, true);
    if (!is_array($dati)) {
        $guasti[] = basename($path);
        return $vuoto;
    }
    return $dati;
}
