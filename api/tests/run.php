<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FoundationTest.php';
require __DIR__ . '/ApiTest.php';
require __DIR__ . '/CoreRulesTest.php';
require __DIR__ . '/ScenarioTest.php';
require __DIR__ . '/VanillaTestSupport.php';
require __DIR__ . '/VanillaGeometryTest.php';
require __DIR__ . '/VanillaDraftTest.php';

$failures = 0;
$tests = array_merge(foundation_tests(), api_tests(), core_rules_tests(), scenario_tests(), vanilla_geometry_tests(), vanilla_draft_tests());
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
