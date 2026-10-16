<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\UnitConditions;

final class Hibernation extends Ability
{
    public function onPlaced(array $state, string $unitId, array &$events): array
    {
        return UnitConditions::apply($state, 'Hibernating', $unitId, $unitId, ['kind' => 'untilCleared'], $events);
    }

    public function onActivate(array $state, string $unitId, array &$events): array
    {
        return UnitConditions::clear($state, $unitId, 'Hibernating', $events);
    }

    public function onTurnEnd(array $state, string $unitId, array &$events): array
    {
        if (in_array($unitId, $state['turn']['activated'], true) || UnitConditions::has($state, $unitId, 'Hibernating')) {
            return $state;
        }
        return $this->onPlaced($state, $unitId, $events);
    }
}
