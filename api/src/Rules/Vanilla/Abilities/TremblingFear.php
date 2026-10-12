<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

final class TremblingFear extends Ability
{
    public function statDelta(array $state, string $ownerId, string $unitId, string $stat, array $context): int
    {
        $attacking = $stat === 'power'
            && ($context['defenderId'] ?? null) === $ownerId
            && ($context['attackerId'] ?? null) === $unitId;
        return $attacking && !$state['units'][$unitId]['isHero'] ? -1 : 0;
    }
}
