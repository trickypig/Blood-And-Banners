<?php
declare(strict_types=1);

use BloodAndBanners\Game\GameServiceException;
use BloodAndBanners\Rules\Vanilla\Board;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Turn;

function test_catalog(): Catalog
{
    return new Catalog(__DIR__ . '/fixtures/catalog.json');
}

function expect_game_error(string $code, callable $action): void
{
    try {
        $action();
    } catch (GameServiceException $error) {
        expect_same($code, $error->errorCode, "expected game error {$code}");
        return;
    }
    throw new RuntimeException("expected game error {$code} but the action succeeded");
}

function proving_field_board(): array
{
    $definition = json_decode(
        (string)file_get_contents(dirname(__DIR__) . '/scenarios/proving-field.json'),
        true,
        flags: JSON_THROW_ON_ERROR
    );
    return Board::fromScenario($definition);
}

function vanilla_board(array $elevations, array $blockers = []): array
{
    $board = [];
    foreach ($elevations as $key => $elevation) {
        $board[(string)$key] = [
            'elevation' => $elevation,
            'sightBlocker' => in_array((string)$key, $blockers, true),
            'playerStart' => null,
        ];
    }
    return $board;
}

function vanilla_line(int $length): array
{
    $elevations = [];
    for ($q = 0; $q < $length; $q++) {
        $elevations["{$q},0"] = 0;
    }
    return $elevations;
}

function vanilla_unit(string $unitId, string $seatId, ?string $hex, array $stats = []): array
{
    return $stats + [
        'unitId' => $unitId, 'seatId' => $seatId, 'isHero' => false, 'name' => $unitId, 'image' => '',
        'move' => 2, 'range' => 1, 'power' => 2, 'defense' => 1, 'hp' => 3, 'wounds' => 0, 'hex' => $hex,
    ];
}

function vanilla_battle(array $board, array $units, string $activeSeat = 'seat-a'): array
{
    $state = [
        'phase' => 'active',
        'seats' => ['seat-a', 'seat-b'],
        'board' => $board,
        'initiative' => ['rolls' => ['seat-a' => [20], 'seat-b' => [1]], 'order' => ['seat-a', 'seat-b']],
        'draft' => [
            'pickingSeatId' => null,
            'firstSeatId' => 'seat-a',
            'startBySeat' => ['seat-a' => 1, 'seat-b' => 2],
            'heroBySeat' => ['seat-a' => 'Test Captain', 'seat-b' => 'Test Marshal'],
            'armyBySeat' => ['seat-a' => 'Red Company', 'seat-b' => 'Blue Company'],
        ],
        'units' => [],
        'effects' => [],
        'pending' => null,
        'turn' => Turn::idle(),
        'result' => null,
    ];
    foreach ($units as $unit) {
        $state['units'][$unit['unitId']] = $unit;
    }
    $state['turn'] = Turn::fresh($state, $activeSeat, 1);
    return $state;
}

function vanilla_command(array $state, string $seatId, string $kind, array $payload = [], array $rolls = [], ?int $now = null): array
{
    return (new MatchEngine(test_catalog()))->apply($state, $seatId, $kind, $payload, new SequenceRandom($rolls), $now);
}
