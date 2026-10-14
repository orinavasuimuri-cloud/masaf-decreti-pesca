<?php
declare(strict_types=1);

function accesso_avvia_sessione(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function accesso_config(): array
{
    static $cfg = null;
    $cfg ??= require __DIR__ . '/accesso_config.php';
    return $cfg;
}

function accesso_loggato(): bool
{
    accesso_avvia_sessione();
    return isset($_SESSION['utente']);
}

function accesso_richiedi(): void
{
    if (!accesso_loggato()) {
        header('Location: login.php');
        exit;
    }
}

function accesso_entra(string $email): void
{
    accesso_avvia_sessione();
    session_regenerate_id(true);
    $_SESSION['utente'] = strtolower($email);
}

function accesso_esci(): void
{
    accesso_avvia_sessione();
    $_SESSION = [];
    session_destroy();
}

function accesso_email_autorizzata(string $email): bool
{
    return array_key_exists(strtolower($email), accesso_config()['utenti']);
}

function accesso_password_ok(string $email, string $password): bool
{
    $hash = accesso_config()['utenti'][strtolower($email)] ?? '';
    return $hash !== '' && password_verify($password, $hash);
}

function accesso_google_redirect_uri(): string
{
    $https = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
    $cartella = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    return $https . '://' . $_SERVER['HTTP_HOST'] . $cartella . '/login_google_callback.php';
}

function accesso_google_url(string $state): string
{
    $g = accesso_config()['google'];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => $g['client_id'],
        'redirect_uri'  => accesso_google_redirect_uri(),
        'response_type' => 'code',
        'scope'         => 'openid email',
        'state'         => $state,
        'prompt'        => 'select_account',
    ]);
}

function accesso_google_email(string $code): ?string
{
    $g = accesso_config()['google'];
    $token = accesso_http('POST', 'https://oauth2.googleapis.com/token', [
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'code'          => $code,
        'client_id'     => $g['client_id'],
        'client_secret' => $g['client_secret'],
        'redirect_uri'  => accesso_google_redirect_uri(),
        'grant_type'    => 'authorization_code',
    ]));
    $access = is_string($token) ? (json_decode($token, true)['access_token'] ?? null) : null;
    if (!is_string($access)) {
        return null;
    }

    $info = accesso_http('GET', 'https://openidconnect.googleapis.com/v1/userinfo', [
        'Authorization: Bearer ' . $access,
    ], null);
    $dati = is_string($info) ? json_decode($info, true) : null;
    if (!is_array($dati) || ($dati['email_verified'] ?? false) !== true || !isset($dati['email'])) {
        return null;
    }
    return (string) $dati['email'];
}

function accesso_http(string $metodo, string $url, array $intestazioni, ?string $corpo): string|false
{
    $ctx = stream_context_create(['http' => [
        'method'        => $metodo,
        'header'        => implode("\r\n", $intestazioni),
        'content'       => $corpo ?? '',
        'timeout'       => 15,
        'ignore_errors' => true,
    ]]);
    return @file_get_contents($url, false, $ctx);
}
