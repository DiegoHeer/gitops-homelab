<?php

// Minimal test runner: the larapaper image ships without PHPUnit.
// Usage: php tests/run.php

$failures = 0;
$passes = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $passes;

    if ($ok) {
        $passes++;
        echo "PASS {$name}\n";

        return;
    }

    $failures++;
    echo "FAIL {$name}".($detail === '' ? '' : ": {$detail}")."\n";
}

function amsterdam(string $time): DateTimeImmutable
{
    return new DateTimeImmutable($time, new DateTimeZone('Europe/Amsterdam'));
}

function logic(string $name): Closure
{
    return require __DIR__."/../logic/{$name}.php";
}

$tests = glob(__DIR__.'/*_test.php');
sort($tests);
foreach ($tests as $test) {
    require $test;
}

echo "{$passes} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
