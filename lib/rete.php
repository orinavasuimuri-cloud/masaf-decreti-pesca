<?php
declare(strict_types=1);

/**
 * L'unico punto da cui il progetto esce in rete.
 *
 * I cinque programmi di raccolta avevano ciascuno la propria funzione di
 * download, cinque copie della stessa riga di curl lanciata con shell_exec().
 * Funziona sulla macchina di sviluppo, dove openssl non e' abilitato e curl e'
 * l'unica strada, ma lega il progetto alla funzione che gli hosting condivisi
 * disattivano piu' spesso - e a ragione, perche' esegue comandi di sistema.
 *
 * Qui si prova invece cio' che c'e', nell'ordine in cui conviene averlo:
 *
 *   1. estensione curl di PHP  nessun processo esterno, redirect e header
 *                              gestiti dalla libreria
 *   2. wrapper https://        nativo, richiede openssl
 *   3. shell_exec('curl')      l'ultima, perche' avvia un processo e perche'
 *                              e' quella che puo' non esserci
 *
 * Cosi' l'installazione non dipende piu' da quale delle tre l'amministratore
 * lascia attiva: ne basta una qualsiasi.
 */

/**
 * Cosa questa installazione mette a disposizione per uscire in rete.
 *
 * @return array{curl_ext:bool, https:bool, shell:bool}
 */
function rete_disponibili(): array
{
    $disabilitate = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return [
        'curl_ext' => function_exists('curl_init'),
        'https'    => in_array('https', stream_get_wrappers(), true),
        // function_exists() da solo non basta: disable_functions lascia la
        // funzione dichiarata ma la fa fallire alla chiamata.
        'shell'    => function_exists('shell_exec') && !in_array('shell_exec', $disabilitate, true),
    ];
}

/**
 * Quale strada usare, date le disponibilita'. Stringa vuota se non ce n'e'.
 *
 * Funzione separata dall'esecuzione perche' e' l'unico pezzo di questa logica
 * che si puo' verificare ovunque: sulla macchina di sviluppo esiste solo
 * shell_exec, e un controllo sul comportamento reale non potrebbe mai
 * esercitare gli altri due rami.
 */
function rete_strategia(bool $curlExt, bool $https, bool $shell): string
{
    if ($curlExt) {
        return 'curl_ext';
    }
    if ($https) {
        return 'https';
    }
    if ($shell) {
        return 'shell';
    }
    return '';
}

/**
 * Scarica una risorsa, o lancia se non ci riesce.
 *
 * $conHeader antepone gli header alla risposta, come "curl -i": serve a
 * bandi_fetcher.php per leggere X-WP-Total, che l'API espone solo li'.
 *
 * Una risposta vuota e' trattata come un guasto e non come "pagina vuota":
 * tutte le fonti di questo progetto restituiscono sempre qualcosa, e un corpo
 * vuoto significa che la richiesta non e' arrivata a destinazione. Distinguere
 * i due casi conta, perche' un parser che riceve la stringa vuota lancia a sua
 * volta, ma con un messaggio che parla di struttura cambiata invece che di rete.
 *
 * @throws RuntimeException se nessuna strada e' disponibile o il download fallisce
 */
function rete_scarica(string $url, string $userAgent, bool $conHeader = false, int $timeout = 25): string
{
    $d = rete_disponibili();
    $strategia = rete_strategia($d['curl_ext'], $d['https'], $d['shell']);

    $corpo = match ($strategia) {
        'curl_ext' => rete_scarica_curl_ext($url, $userAgent, $conHeader, $timeout),
        'https'    => rete_scarica_https($url, $userAgent, $conHeader, $timeout),
        'shell'    => rete_scarica_shell($url, $userAgent, $conHeader, $timeout),
        default    => throw new RuntimeException(
            'nessun modo di uscire in rete: mancano insieme l\'estensione curl, '
            . 'il wrapper https:// (openssl) e shell_exec'
        ),
    };

    if (trim($corpo) === '') {
        throw new RuntimeException("download fallito ($strategia): $url");
    }
    return $corpo;
}

/** @throws RuntimeException */
function rete_scarica_curl_ext(string $url, string $userAgent, bool $conHeader, int $timeout): string
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException("curl_init fallito: $url");
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => $userAgent,
        CURLOPT_HEADER         => $conHeader,
    ]);
    $corpo = curl_exec($ch);
    $errore = curl_error($ch);
    curl_close($ch);
    if ($corpo === false) {
        throw new RuntimeException("download fallito (curl): $url" . ($errore !== '' ? " - $errore" : ''));
    }
    return (string) $corpo;
}

/** @throws RuntimeException */
function rete_scarica_https(string $url, string $userAgent, bool $conHeader, int $timeout): string
{
    $ctx = stream_context_create(['http' => [
        'method'          => 'GET',
        'header'          => "User-Agent: $userAgent\r\n",
        'timeout'         => $timeout,
        'follow_location' => 1,
        'max_redirects'   => 5,
        // Senza questo un 404 fa restituire false e si perde il corpo, che a
        // volte e' l'unico posto dove la fonte spiega cosa e' successo.
        'ignore_errors'   => true,
    ]]);
    $corpo = @file_get_contents($url, false, $ctx);
    if ($corpo === false) {
        throw new RuntimeException("download fallito (https): $url");
    }
    if ($conHeader) {
        // $http_response_header e' popolata da file_get_contents nello scope
        // locale: si ricompone la forma di "curl -i", che e' quella che i
        // chiamanti sanno leggere.
        $header = $http_response_header ?? [];
        return implode("\r\n", $header) . "\r\n\r\n" . $corpo;
    }
    return $corpo;
}

/** @throws RuntimeException */
function rete_scarica_shell(string $url, string $userAgent, bool $conHeader, int $timeout): string
{
    $cmd = sprintf(
        'curl -s %s -L --max-time %d -A %s %s',
        $conHeader ? '-i' : '',
        $timeout,
        escapeshellarg($userAgent),
        escapeshellarg($url)
    );
    $corpo = shell_exec($cmd);
    if (!is_string($corpo)) {
        throw new RuntimeException("download fallito (shell): $url");
    }
    return $corpo;
}
