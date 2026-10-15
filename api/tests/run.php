<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/ConfigTest.php';
require __DIR__ . '/HttpRequestFactoryTest.php';
require __DIR__ . '/GatewayTest.php';
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
require __DIR__ . '/CoopServiceTest.php';
require __DIR__ . '/CoopTestSupport.php';
require __DIR__ . '/CoopSidesTest.php';
require __DIR__ . '/CoopSetupTest.php';
require __DIR__ . '/CoopDraftTest.php';
require __DIR__ . '/ModeScheduleTest.php';
require __DIR__ . '/CoopBattleTest.php';
require __DIR__ . '/MonsterAbilityTest.php';
require __DIR__ . '/MonsterPoisonTest.php';
require __DIR__ . '/MonsterControlTest.php';
require __DIR__ . '/MonsterExtraAttackTest.php';
require __DIR__ . '/MonsterMushroomTest.php';
require __DIR__ . '/MonsterWaterTest.php';
require __DIR__ . '/CommanderNameTest.php';

$failures = 0;
$tests = array_merge(config_tests(), http_request_factory_tests(), gateway_tests(), foundation_tests(), api_tests(), core_rules_tests(), scenario_tests(), vanilla_geometry_tests(), vanilla_draft_tests(), vanilla_battle_tests(), vanilla_ability_tests(), vanilla_forced_tests(), vanilla_terrain_tests(), vanilla_ice_tests(), vanilla_match_service_tests(), coop_service_tests(), coop_sides_tests(), coop_setup_tests(), coop_draft_tests(), mode_schedule_tests(), coop_battle_tests(), monster_ability_tests(), monster_poison_tests(), monster_control_tests(), monster_extra_attack_tests(), monster_mushroom_tests(), monster_water_tests(), commander_name_tests());
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
