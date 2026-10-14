<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/accesso.php';

accesso_avvia_sessione();
$attesoStato = $_SESSION['google_stato'] ?? null;
unset($_SESSION['google_stato']);

$stato = (string) ($_GET['state'] ?? '');
$code  = (string) ($_GET['code'] ?? '');

if ($attesoStato === null || !hash_equals($attesoStato, $stato) || $code === '') {
    http_response_code(400);
    exit('Accesso non valido. Torna alla pagina di accesso e riprova.');
}

$email = accesso_google_email($code);
if ($email === null || !accesso_email_autorizzata($email)) {
    http_response_code(403);
    exit('Questo account Google non è autorizzato.');
}

accesso_entra($email);
header('Location: index.php');
exit;
