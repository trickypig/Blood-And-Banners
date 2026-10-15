<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\Sides;

final class FrighteningPresence extends Ability
{
    public function statDelta(array $state, string $ownerId, string $unitId, string $stat, array $context): int
    {
        $unit = $state['units'][$unitId];
        return $stat === 'power' && !$unit['isHero'] && $unit['hex'] !== null
            && Sides::enemies($state, $ownerId, $unitId)
            && Hex::distance($state['units'][$ownerId]['hex'], $unit['hex']) === 1 ? -1 : 0;
    }
}
