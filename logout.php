<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/accesso.php';

accesso_esci();
header('Location: login.php');
exit;
