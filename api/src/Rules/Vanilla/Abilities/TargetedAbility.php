<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

/** An Ability-type ability: used once per turn by an activated unit, on top of its move and attack. */
abstract class TargetedAbility extends Ability
{
    abstract public function targets(array $state, string $unitId): array;

    abstract public function apply(array $state, string $unitId, string $targetId, array &$events): array;
}
