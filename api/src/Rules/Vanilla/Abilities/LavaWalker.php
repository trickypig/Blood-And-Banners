<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

final class LavaWalker extends Ability
{
    public function ignoresTerrain(string $terrain, string $movementKind): bool
    {
        return $terrain === 'lava';
    }

    public function defenseDeltaForDamageType(string $damageType): int
    {
        return match ($damageType) {
            'fire' => 2,
            'water' => -2,
            default => 0,
        };
    }
}
