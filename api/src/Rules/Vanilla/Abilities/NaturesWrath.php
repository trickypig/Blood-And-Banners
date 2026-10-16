<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Conditions\Enraged;
use BloodAndBanners\Rules\Vanilla\UnitConditions;

final class NaturesWrath extends Ability
{
    public function onUnitDefeated(array $state, string $ownerId, array $defeated, array &$events): array
    {
        if (($defeated['biome'] ?? null) !== 'Forest') {
            return $state;
        }
        $level = (int)(UnitConditions::named($state, $ownerId, 'Enraged')['data']['level'] ?? 0);
        return Enraged::apply($state, $ownerId, $level + 2, $events);
    }
}
