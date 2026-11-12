<?php
declare(strict_types=1);

/**
 * Categorizzazione degli atti dalla pagina di amministrazione: inserire un
 * atto "da rivedere" in una sezione del registro, oppure escluderlo.
 *
 * Gli archivi toccati (catalog.json, known.json, gazzetta.json, excluded.json)
 * li scrivono anche i programmi di raccolta, quindi ogni modifica passa dal
 * lock comune di lib/lock.php: senza, un clic durante un giro del server
 * perderebbe l'una o l'altra modifica.
 */

require_once __DIR__ . '/lock.php';

/**
 * Valida i campi del modulo e costruisce la voce di catalogo.
 *
 * @param array<string, string> $in  campi del modulo (sezione, ref, title, desc, pdf)
 * @param list<string> $sezioni      id delle sezioni esistenti
 * @return array|string La voce, oppure il messaggio d'errore.
 */
function curatela_voce(array $in, array $sezioni, ?int $masafId, ?string $urlEsterno): array|string
{
    // Un modulo inviato in codifica Latin-1 farebbe fallire il salvataggio in
    // JSON: si converte invece di perdere la modifica.
    $in = array_map(static function ($v): string {
        $v = (string) $v;
        return preg_match('//u', $v) === 1 ? $v : (string) @iconv('Windows-1252', 'UTF-8//IGNORE', $v);
    }, $in);
    $sezione = trim($in['sezione'] ?? '');
    $ref     = trim($in['ref'] ?? '');
    $titolo  = trim($in['title'] ?? '');
    $desc    = trim($in['desc'] ?? '');
    $pdf     = trim($in['pdf'] ?? '');

    if (!in_array($sezione, $sezioni, true)) {
        return 'Scegli una sezione del registro.';
    }
    if ($titolo === '' || $ref === '' || $desc === '') {
        return 'Riferimento, titolo e descrizione sono obbligatori.';
    }
    if ($pdf !== '' && stripos($pdf, 'https://') !== 0) {
        return 'Il link al PDF deve cominciare con https://';
    }
    if ($masafId === null && ($urlEsterno === null || stripos($urlEsterno, 'https://') !== 0)) {
        return 'Manca il collegamento all\'atto originale.';
    }

    // L'anno è esplicito nel catalogo: dal riferimento se c'è una data, altrimenti quello corrente.
    $anno = preg_match('#\b\d{1,2}/\d{1,2}/((?:19|20)\d{2})\b#', $ref, $m) === 1 ? $m[1] : date('Y');

    $voce = ['masaf_id' => $masafId, 'year' => $anno];
    if ($masafId === null) {
        $voce['external_url'] = $urlEsterno;
    }
    $voce += ['ref' => $ref, 'title' => $titolo, 'desc' => $desc];
    if ($pdf !== '') {
        $voce['pdf'] = $pdf;
    }
    return $voce;
}

/** Mette la voce in cima alla sezione: le più recenti stanno in alto. */
function catalogo_inserisci(array $catalog, string $sezioneId, array $voce): array
{
    foreach ($catalog['sections'] ?? [] as $s) {
        foreach ($s['items'] ?? [] as $it) {
            if ($voce['masaf_id'] !== null && ($it['masaf_id'] ?? null) === $voce['masaf_id']) {
                throw new RuntimeException('Questo atto è già nel registro, nella sezione "' . $s['title'] . '".');
            }
        }
    }
    foreach ($catalog['sections'] as $i => $s) {
        if ($s['id'] === $sezioneId) {
            array_unshift($catalog['sections'][$i]['items'], $voce);
            return $catalog;
        }
    }
    throw new RuntimeException('Sezione sconosciuta.');
}

function curatela_leggi(string $path): array
{
    $raw = @file_get_contents($path);
    $dati = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($dati)) {
        throw new RuntimeException('Archivio illeggibile: ' . basename($path) . '. Nessuna modifica fatta.');
    }
    return $dati;
}

function curatela_scrivi(string $path, array $dati): void
{
    $json = json_encode($dati, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp  = $path . '.tmp';
    $ok = $json !== false && file_put_contents($tmp, $json . "\n") === strlen($json) + 1;
    // Su Windows rename() fallisce se in quell'istante un altro processo ha il
    // file aperto in lettura (la pagina stessa, l'antivirus): pochi tentativi bastano.
    for ($i = 0; $ok && $i < 5; $i++) {
        if (@rename($tmp, $path)) {
            return;
        }
        usleep(200000);
    }
    @unlink($tmp);
    throw new RuntimeException('Impossibile salvare ' . basename($path) . ': controlla i permessi di data/.');
}

/**
 * Esegue $modifica con il lock comune preso. Aspetta poco: un programma di
 * raccolta può tenerlo qualche minuto, e la pagina non deve restare appesa.
 */
function curatela_con_lock(string $dataDir, callable $modifica): void
{
    $path = $dataDir . '/.fetch.lock';
    $lock = lock_acquisisci_entro($path, 20);
    if ($lock === false) {
        throw new RuntimeException(lock_apribile($path)
            ? 'È in corso un aggiornamento dei dati: riprova fra un paio di minuti.'
            : 'Il file di blocco in data/ non si apre: controlla i permessi della cartella.');
    }
    try {
        $modifica();
    } finally {
        lock_rilascia($lock);
    }
}
