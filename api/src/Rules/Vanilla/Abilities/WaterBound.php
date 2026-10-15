<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Water;

final class WaterBound extends Ability
{
    public function allowsVoluntaryHex(array $state, array $unit, string $hex): bool
    {
        return Water::contains($state, $hex);
    }
}
