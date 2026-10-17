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
        $attacker = $state['units'][$unitId];
        $fearless = $attacker['isHero'] || ($attacker['tier'] ?? null) === 'boss';
        return $attacking && !$fearless ? -1 : 0;
    }
}
