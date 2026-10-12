<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

final class RecklessFury extends Ability
{
    public function statDelta(array $state, string $ownerId, string $unitId, string $stat, array $context): int
    {
        return $stat === 'power' && $unitId === $ownerId ? (int)$state['units'][$ownerId]['wounds'] : 0;
    }
}
