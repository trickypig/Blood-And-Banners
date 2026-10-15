<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\RandomSource;

/** A whole movement replacement with empty hex destinations and terrain previews. */
abstract class MovementAbility extends Ability
{
    abstract public function unavailable(array $state, string $unitId): ?string;
    abstract public function options(array $state, string $unitId): array;
    abstract public function apply(array $state, string $unitId, string $toHex, RandomSource $random, array &$events): array;
}
