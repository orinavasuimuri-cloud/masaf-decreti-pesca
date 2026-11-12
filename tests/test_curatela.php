<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/curatela.php';

$sezioni = ['gamberi', 'fermo'];
$base = ['sezione' => 'gamberi', 'ref' => 'D.D. n. 614745 · 09/11/2026', 'title' => 'Stop gamberi', 'desc' => 'Fermo fino a fine anno.', 'pdf' => ''];

// --- voce valida da un atto MASAF ---
$v = curatela_voce($base, $sezioni, 25447, null);
t_eq($v, ['masaf_id' => 25447, 'year' => '2026', 'ref' => 'D.D. n. 614745 · 09/11/2026', 'title' => 'Stop gamberi', 'desc' => 'Fermo fino a fine anno.'],
    'curatela: voce MASAF senza PDF, anno ricavato dal riferimento');

// --- voce da un atto della Gazzetta: niente masaf_id, link esterno obbligatorio ---
$v = curatela_voce(['ref' => 'G.U. n. 249 · 26/10/2026'] + $base, $sezioni, null, 'https://www.gazzettaufficiale.it/atto/x');
t_true(array_key_exists('masaf_id', $v) && $v['masaf_id'] === null, 'curatela: voce Gazzetta con masaf_id nullo');
t_eq($v['external_url'] ?? null, 'https://www.gazzettaufficiale.it/atto/x', 'curatela: voce Gazzetta con link all\'atto');

// --- testo arrivato in Latin-1 ("·" come byte B7): convertito, non perso ---
$v = curatela_voce(['ref' => "D.D. n. 1 \xB7 01/11/2026"] + $base, $sezioni, 3, null);
t_eq($v['ref'] ?? null, 'D.D. n. 1 · 01/11/2026', 'curatela: riferimento in Latin-1 convertito in UTF-8');
t_true(json_encode($v) !== false, 'curatela: la voce convertita si salva in JSON');

// --- errori ---
t_true(is_string(curatela_voce(['sezione' => 'inesistente'] + $base, $sezioni, 1, null)), 'curatela: sezione sconosciuta rifiutata');
t_true(is_string(curatela_voce(['title' => '  '] + $base, $sezioni, 1, null)), 'curatela: titolo vuoto rifiutato');
t_true(is_string(curatela_voce(['pdf' => 'javascript:alert(1)'] + $base, $sezioni, 1, null)), 'curatela: PDF che non è https rifiutato');
t_true(is_string(curatela_voce($base, $sezioni, null, 'javascript:alert(1)')), 'curatela: link esterno non https rifiutato');

// --- inserimento in testa alla sezione, e niente doppioni ---
$catalog = ['sections' => [
    ['id' => 'gamberi', 'title' => 'Gamberi', 'items' => [['masaf_id' => 1, 'title' => 'vecchia']]],
    ['id' => 'fermo', 'title' => 'Fermo', 'items' => []],
]];
$nuovo = catalogo_inserisci($catalog, 'gamberi', ['masaf_id' => 2, 'title' => 'nuova']);
t_eq(array_column($nuovo['sections'][0]['items'], 'title'), ['nuova', 'vecchia'], 'curatela: la voce nuova va in cima');
$errore = null;
try {
    catalogo_inserisci($nuovo, 'fermo', ['masaf_id' => 2, 'title' => 'doppia']);
} catch (RuntimeException $e) {
    $errore = $e->getMessage();
}
t_true($errore !== null && str_contains($errore, 'Gamberi'), 'curatela: un atto già in registro non si inserisce due volte');
