<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/accesso.php';

accesso_avvia_sessione();
if (accesso_loggato()) {
    header('Location: index.php');
    exit;
}

$errore = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (accesso_password_ok($email, $password)) {
        accesso_entra($email);
        header('Location: index.php');
        exit;
    }
    usleep(800000);
    $errore = 'Email o password non corrette.';
}

$googleAttivo = accesso_config()['google']['client_id'] !== '';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accesso</title>
<link rel="stylesheet" href="assets/style.css">
<style>
  .accesso { max-width: 340px; margin: 12vh auto; padding: 24px; }
  .accesso label { display: block; margin-top: 12px; font-size: 14px; }
  .accesso input { width: 100%; box-sizing: border-box; padding: 8px; margin-top: 4px; }
  .accesso button, .accesso .google { display: block; width: 100%; margin-top: 16px; padding: 10px; text-align: center; box-sizing: border-box; cursor: pointer; }
  .accesso .errore { color: #c0392b; margin-top: 12px; }
  .accesso .separatore { margin: 20px 0 0; text-align: center; font-size: 13px; opacity: .7; }
</style>
</head>
<body>
<main class="accesso">
  <h1>Accesso riservato</h1>

  <?php if ($errore !== null): ?>
    <p class="errore"><?= htmlspecialchars($errore, ENT_QUOTES, 'UTF-8') ?></p>
  <?php endif; ?>

  <form method="post" action="login.php">
    <label>Email
      <input type="email" name="email" required autocomplete="username">
    </label>
    <label>Password
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button type="submit">Entra</button>
  </form>

  <?php if ($googleAttivo): ?>
    <p class="separatore">oppure</p>
    <a class="google" href="login_google.php">Accedi con Google</a>
  <?php endif; ?>
</main>
</body>
</html>
