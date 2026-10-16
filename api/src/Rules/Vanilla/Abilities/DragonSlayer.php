<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\UnitConditions;

final class DragonSlayer extends Ability
{
    public function onDefeat(array $state, string $unitId, array $defeated, ?string $attackerId, ?RandomSource $random, array &$events, bool $directAction = false): array
    {
        $slayer = $state['units'][$attackerId ?? ''] ?? null;
        if (!$directAction || $slayer === null || $slayer['hex'] === null || $attackerId === $unitId) {
            return $state;
        }
        $level = (int)(UnitConditions::named($state, $attackerId, 'Dragon Slayer')['data']['level'] ?? 0);
        return UnitConditions::apply($state, 'Dragon Slayer', $attackerId, $unitId, ['kind' => 'permanent'], $events, ['level' => $level + 1]);
    }
}
