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
require __DIR__ . '/VanillaBattleTest.php';
require __DIR__ . '/VanillaAbilityTest.php';
require __DIR__ . '/VanillaForcedTest.php';
require __DIR__ . '/VanillaTerrainTest.php';
require __DIR__ . '/VanillaIceTest.php';
require __DIR__ . '/VanillaMatchServiceTest.php';
require __DIR__ . '/CoopTestSupport.php';
require __DIR__ . '/CoopSidesTest.php';
require __DIR__ . '/CoopSetupTest.php';
require __DIR__ . '/CoopDraftTest.php';
require __DIR__ . '/ModeScheduleTest.php';
require __DIR__ . '/CoopBattleTest.php';

$failures = 0;
$tests = array_merge(foundation_tests(), api_tests(), core_rules_tests(), scenario_tests(), vanilla_geometry_tests(), vanilla_draft_tests(), vanilla_battle_tests(), vanilla_ability_tests(), vanilla_forced_tests(), vanilla_terrain_tests(), vanilla_ice_tests(), vanilla_match_service_tests(), coop_sides_tests(), coop_setup_tests(), coop_draft_tests(), mode_schedule_tests(), coop_battle_tests());
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
