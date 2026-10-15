<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

/** Dual Strike, Twin Strikes, Dual Strikes: two attacks per activation. */
final class MultiStrike extends Ability
{
    public function attacksPerActivation(array $unit = [], array $context = []): int
    {
        return 2;
    }
}
