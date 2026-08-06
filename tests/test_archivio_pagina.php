<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/archivio_pagina.php';

// --- assente e corrotto sono due fatti diversi ---
// Confonderli era il difetto: la pagina mostrava una coda vuota tanto quando
// non c'era ancora nulla quanto quando l'archivio non si leggeva, e nel secondo
// caso il curatore concludeva che non c'era niente da fare.

$base = sys_get_temp_dir() . '/masaf_arch_' . getmypid();
$vuoto = ['items' => []];

// File che non c'e': stato iniziale legittimo, nessun guasto da dichiarare.
$guasti = [];
t_eq(archivio_pagina($base . '_manca.json', $vuoto, $guasti), $vuoto, 'archivio: un file assente deve dare la forma vuota');
t_eq($guasti, [], 'archivio: un file assente non e un guasto');

// File valido: si legge, e non finisce fra i guasti.
$buono = $base . '_buono.json';
file_put_contents($buono, json_encode(['items' => [['id' => 'a']]]));
$guasti = [];
$letto = archivio_pagina($buono, $vuoto, $guasti);
t_eq(count($letto['items']), 1, 'archivio: un file valido deve essere letto');
t_eq($guasti, [], 'archivio: un file valido non e un guasto');

// File presente ma troncato: si degrada alla forma vuota - la pagina non deve
// morire - ma il nome finisce fra i guasti, perche' vada dichiarato.
$rotto = $base . '_rotto.json';
file_put_contents($rotto, '{"items": [{"id": "a"');
$guasti = [];
t_eq(archivio_pagina($rotto, $vuoto, $guasti), $vuoto, 'archivio: un file corrotto deve degradare alla forma vuota');
t_eq($guasti, [basename($rotto)], 'archivio: un file corrotto deve essere dichiarato fra i guasti');

// JSON valido ma non un oggetto: e' comunque inservibile per la pagina.
$scalare = $base . '_scalare.json';
file_put_contents($scalare, '"una stringa"');
$guasti = [];
t_eq(archivio_pagina($scalare, $vuoto, $guasti), $vuoto, 'archivio: un JSON che non e un oggetto deve degradare');
t_eq($guasti, [basename($scalare)], 'archivio: un JSON che non e un oggetto va dichiarato');

// I guasti si accumulano: la pagina li elenca tutti in un avviso solo, invece
// di dichiarare il primo e tacere gli altri.
$guasti = [];
archivio_pagina($rotto, $vuoto, $guasti);
archivio_pagina($scalare, $vuoto, $guasti);
archivio_pagina($buono, $vuoto, $guasti);
t_eq(count($guasti), 2, 'archivio: i guasti di piu file devono accumularsi');

@unlink($buono);
@unlink($rotto);
@unlink($scalare);
