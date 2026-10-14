<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/accesso.php';

accesso_avvia_sessione();
$stato = bin2hex(random_bytes(16));
$_SESSION['google_stato'] = $stato;
header('Location: ' . accesso_google_url($stato));
exit;
