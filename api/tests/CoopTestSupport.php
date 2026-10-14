<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Turn;

/** A battle where seat-a and seat-c are allied heroes and seat-b is the Void. */
function allied_battle(array $board, array $units, string $activeSeat = 'seat-a'): array
{
    $state = vanilla_battle($board, [], $activeSeat);
    $state['seats'] = ['seat-a', 'seat-b', 'seat-c'];
    $state['sides'] = [
        'heroes' => ['kind' => 'heroes', 'seatIds' => ['seat-a', 'seat-c']],
        'void' => ['kind' => 'void', 'seatIds' => ['seat-b']],
    ];
    foreach ($units as $unit) {
        $unit['side'] ??= $unit['seatId'] === 'seat-b' ? 'void' : 'heroes';
        $state['units'][$unit['unitId']] = $unit;
    }
    $state['turn'] = Turn::fresh($state, $activeSeat, 1);
    return $state;
}

/** 9×9 board: hero start at 1,4; every hex with q >= 7 is a void spawn hex. */
function coop_board(): array
{
    $elevations = [];
    for ($q = 0; $q < 9; $q++) {
        for ($r = 0; $r < 9; $r++) {
            $elevations["{$q},{$r}"] = 0;
        }
    }
    $board = vanilla_board($elevations);
    foreach ($board as $key => $hex) {
        $board[$key]['spawnZone'] = (int)explode(',', (string)$key)[0] >= 7 ? 'void' : null;
    }
    $board['1,4']['playerStart'] = 1;
    return $board;
}

/** Hero seats hero-a (rolls 15) and hero-b (rolls 5) against void-lord; budget 4 + 8 × 2 = 20. */
function coop_start(?array $board = null, array $heroSeats = ['hero-a', 'hero-b'], array $rolls = [15, 5]): array
{
    return (new MatchEngine(test_catalog()))->start(
        array_merge($heroSeats, ['void-lord']),
        $board ?? coop_board(),
        new SequenceRandom($rolls),
        'coop',
        ['voidLordSeatId' => 'void-lord', 'coop' => ['basePoints' => 4, 'pointsPerHeroPlayer' => 8]]
    );
}

function coop_command(array $state, string $seatId, string $kind, array $payload = [], array $rolls = []): array
{
    return (new MatchEngine(test_catalog()))->apply($state, $seatId, $kind, $payload, new SequenceRandom($rolls));
}

/** A co-op state at the start of the hero draft with one Test Wolf at 7,4. */
function coop_ready_draft(): array
{
    $state = coop_command(coop_start()['state'], 'void-lord', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '7,4'])['state'];
    return coop_command($state, 'void-lord', 'confirmSetup')['state'];
}

/** Picks that finish the two-seat co-op draft in snake order: a, b, b, a, a, b. */
function coop_draft_picks(): array
{
    return [
        ['hero-a', 'pickHero', ['hero' => 'Test Captain']],
        ['hero-b', 'pickHero', ['hero' => 'Test Marshal']],
        ['hero-b', 'pickHero', ['hero' => 'Test Warden']],
        ['hero-a', 'pickHero', ['hero' => 'Test Ranger']],
        ['hero-a', 'pickArmy', ['army' => 'Red Company']],
        ['hero-b', 'pickArmy', ['army' => 'Blue Company']],
    ];
}
