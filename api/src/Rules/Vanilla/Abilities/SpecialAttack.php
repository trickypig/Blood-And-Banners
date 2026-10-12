<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

/** An Attack-type ability: used instead of the unit's normal attack. */
abstract class SpecialAttack extends Ability
{
    /** Unit ids this attack may target first. */
    abstract public function targets(array $state, string $unitId): array;

    /** Steps resolving the attack against the ordered targets. */
    abstract public function steps(array $state, string $unitId, array $targetIds, array $options = []): array;

    /** Error code for an invalid target list, or null. Single-target attacks accept exactly one listed target. */
    public function validate(array $state, string $unitId, array $targetIds, array $options = []): ?string
    {
        return count($targetIds) === 1 && in_array($targetIds[0], $this->targets($state, $unitId), true)
            ? null
            : 'invalidAbilityTarget';
    }
}
