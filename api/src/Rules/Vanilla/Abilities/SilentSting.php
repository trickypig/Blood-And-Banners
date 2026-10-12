<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

final class SilentSting extends Ability
{
    public function movesAfterAttack(): bool
    {
        return true;
    }
}
