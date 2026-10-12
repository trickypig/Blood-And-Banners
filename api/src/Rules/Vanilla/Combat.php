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
        if (Hex::distance($attacker['hex'], $target['hex']) > Stats::effective($state, $attackerId, 'range')) {
            return 'targetOutOfRange';
        }
        if (!self::visible($state, $attacker['hex'], $target['hex'])) {
            return 'noLineOfSight';
        }
        return null;
    }

    public static function targets(array $state, string $attackerId): array
    {
        return Targeting::enemiesInSight($state, $attackerId, Stats::effective($state, $attackerId, 'range'));
    }

    public static function visible(array $state, string $fromHex, string $toHex): bool
    {
        return (new HexLineOfSight())->isVisible($fromHex, $toHex, self::sightBoard($state));
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
