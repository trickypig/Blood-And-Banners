<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

/** Two normal attacks, or one special attack, without mixing them in an activation. */
final class BranchAttack extends Ability
{
    public function attacksPerActivation(array $unit = [], array $context = []): int
    {
        return !empty($context['specialAttack']) || !empty($context['specialAttackUsed']) ? 1 : 2;
    }
}
