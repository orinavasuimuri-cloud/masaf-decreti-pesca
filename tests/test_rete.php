<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/rete.php';

// --- scelta della strada ---
// E' l'unico pezzo verificabile ovunque: su questa macchina esiste solo
// shell_exec, quindi provare il comportamento reale non eserciterebbe mai gli
// altri due rami - proprio quelli che servono sul server dove il progetto
// andra' a finire.
t_eq(rete_strategia(true,  true,  true),  'curl_ext', 'rete: con tutto disponibile si usa l estensione curl');
t_eq(rete_strategia(true,  false, false), 'curl_ext', 'rete: l estensione curl basta da sola');
t_eq(rete_strategia(false, true,  true),  'https',    'rete: senza estensione curl si passa a https://');
t_eq(rete_strategia(false, true,  false), 'https',    'rete: il wrapper https:// basta da solo');
t_eq(rete_strategia(false, false, true),  'shell',    'rete: shell_exec resta l ultima strada');
// Nessuna strada e' un caso reale: un hosting che disattiva shell_exec senza
// abilitare openssl. Va detto, non lasciato a un errore oscuro piu avanti.
t_eq(rete_strategia(false, false, false), '',         'rete: senza nessuna strada la strategia e vuota');

// L'ordine non e' arbitrario: shell_exec avvia un processo ed e' la funzione
// che gli hosting disattivano piu' spesso, quindi viene per ultima anche
// quando c'e'.
t_true(rete_strategia(true, false, true) !== 'shell', 'rete: shell_exec non deve prevalere sull estensione curl');
t_true(rete_strategia(false, true, true) !== 'shell', 'rete: shell_exec non deve prevalere su https://');

// --- rilevamento dell'ambiente ---
$d = rete_disponibili();
t_eq(array_keys($d), ['curl_ext', 'https', 'shell'], 'rete: le chiavi delle disponibilita sono cambiate');
foreach ($d as $k => $v) {
    t_true(is_bool($v), "rete: la disponibilita $k deve essere un booleano");
}
// Su qualunque macchina dove questi test girano almeno una strada esiste,
// altrimenti nessun fetcher potrebbe funzionare.
t_true(rete_strategia($d['curl_ext'], $d['https'], $d['shell']) !== '', 'rete: questa macchina non ha modo di uscire in rete');

// --- guasti ---
// Un indirizzo inesistente deve lanciare, non restituire stringa vuota: un
// corpo vuoto scambiato per "pagina vuota" farebbe dire al parser che la
// struttura della fonte e' cambiata, mandando a cercare il guasto nel posto
// sbagliato.
$lanciato = false;
try {
    rete_scarica('https://dominio-che-non-esiste-mai.invalid/x', 'prova/1.0', false, 5);
} catch (RuntimeException) {
    $lanciato = true;
}
t_true($lanciato, 'rete: un download fallito deve lanciare RuntimeException');
