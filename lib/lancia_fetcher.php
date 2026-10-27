<?php
declare(strict_types=1);

/**
 * Avvia uno dei programmi di raccolta come processo staccato, senza attendere
 * che finisca: bandi_fetcher.php interroga 42 fonti ed è il più lento dei
 * cinque (vedi docs/DEPLOY.md), una richiesta web bloccata fino alla fine
 * rischierebbe il timeout del browser e del server per un clic sul bottone
 * "Aggiorna ora". Il lock che già protegge ogni fetcher (lib/lock.php) resta
 * l'unica guardia contro le esecuzioni sovrapposte con il Task Scheduler: qui
 * si lancia soltanto, non si duplica quella logica.
 *
 * Su Windows "start" è un comando interno di cmd.exe, non un eseguibile: senza
 * di esso exec()/popen() aspetterebbero la fine del processo figlio prima di
 * restituire il controllo, vanificando lo scopo. Su Unix il "&" di shell
 * stacca il figlio allo stesso modo.
 *
 * L'output va comunque nel log proprio del fetcher (scraper.log, bandi.log,
 * news.log, gazzetta.log): qui si redirige solo per evitare che finisca nei
 * log del web server, non per sostituirsi a quei file.
 */
/**
 * L'eseguibile PHP da riga di comando. Sotto PHP-FPM PHP_BINARY è php-fpm, che
 * non esegue script passati come argomento: lanciato così, il fetcher non
 * partirebbe mai e il bottone non avrebbe alcun effetto.
 */
function php_cli_binary(): string
{
    if (PHP_SAPI === 'cli') {
        return PHP_BINARY;
    }
    $versione = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    foreach ([PHP_BINDIR . "/php$versione", PHP_BINDIR . '/php', "/usr/bin/php$versione", '/usr/bin/php', '/usr/local/bin/php'] as $c) {
        if (@is_executable($c)) {
            return $c;
        }
    }
    return stripos(PHP_BINARY, 'fpm') === false && PHP_BINARY !== '' ? PHP_BINARY : 'php';
}

function lancia_fetcher(string $scriptPath): void
{
    $php    = escapeshellarg(php_cli_binary());
    $script = escapeshellarg($scriptPath);
    $dir    = escapeshellarg(dirname($scriptPath));

    if (stripos(PHP_OS, 'WIN') === 0) {
        $cmd = "start \"\" /B /D $dir $php $script > NUL 2>&1";
        pclose(popen($cmd, 'r'));
        return;
    }

    exec('cd ' . $dir . " && $php $script > /dev/null 2>&1 &");
}
