<?php
declare(strict_types=1);

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = 0;

function t_eq($actual, $expected, string $msg): void {
    if ($actual === $expected) {
        $GLOBALS['t_pass']++;
        return;
    }
    $GLOBALS['t_fail']++;
    echo "FAIL: $msg\n  atteso:  " . var_export($expected, true)
       . "\n  ottenuto: " . var_export($actual, true) . "\n";
}

function t_true($cond, string $msg): void {
    t_eq((bool) $cond, true, $msg);
}

foreach (glob(__DIR__ . '/test_*.php') as $file) {
    require $file;
}

echo "\n{$GLOBALS['t_pass']} passati, {$GLOBALS['t_fail']} falliti\n";
exit($GLOBALS['t_fail'] > 0 ? 1 : 0);
