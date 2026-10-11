<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FoundationTest.php';
require __DIR__ . '/ApiTest.php';

$failures = 0;
$tests = array_merge(foundation_tests(), api_tests());
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
    }
}

fwrite(STDOUT, sprintf("%d tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);
