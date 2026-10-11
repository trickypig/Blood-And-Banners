<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\CustomDice;
use BloodAndBanners\Rules\HexLineOfSight;
use BloodAndBanners\Rules\RandomSource;

final class Combat
{
    public static function targetError(array $state, string $attackerId, string $targetId): ?string
    {
        $attacker = $state['units'][$attackerId];
        $target = $state['units'][$targetId];
        if (Hex::distance($attacker['hex'], $target['hex']) > $attacker['range']) {
            return 'targetOutOfRange';
        }
        if (!(new HexLineOfSight())->isVisible($attacker['hex'], $target['hex'], self::sightBoard($state))) {
            return 'noLineOfSight';
        }
        return null;
    }

    public static function targets(array $state, string $attackerId): array
    {
        $attacker = $state['units'][$attackerId];
        $targets = [];
        foreach ($state['units'] as $unitId => $unit) {
            if ($unit['hex'] === null || $unit['seatId'] === $attacker['seatId']) {
                continue;
            }
            if (self::targetError($state, $attackerId, (string)$unitId) === null) {
                $targets[] = (string)$unitId;
            }
        }
        return $targets;
    }

    public static function attack(array $state, string $attackerId, string $targetId, RandomSource $random): array
    {
        $dice = new CustomDice();
        $attack = $dice->roll($state['units'][$attackerId]['power'], $random);
        $defense = $dice->roll($state['units'][$targetId]['defense'], $random);
        return [
            'attackFaces' => $attack['faces'],
            'defenseFaces' => $defense['faces'],
            'wounds' => max(0, $attack['swords'] - $defense['shields']),
        ];
    }

    public static function disengagementHit(RandomSource $random): array
    {
        $roll = (new CustomDice())->roll(1, $random);
        return ['faces' => $roll['faces'], 'wounds' => $roll['swords']];
    }

    private static function sightBoard(array $state): array
    {
        $board = [];
        foreach ($state['board'] as $key => $hex) {
            $board[(string)$key] = ['elevation' => $hex['elevation'], 'sightBlocker' => $hex['sightBlocker'], 'occupantId' => null];
        }
        foreach ($state['units'] as $unit) {
            if ($unit['hex'] !== null) {
                $board[$unit['hex']]['occupantId'] = $unit['unitId'];
            }
        }
        return $board;
    }
}
