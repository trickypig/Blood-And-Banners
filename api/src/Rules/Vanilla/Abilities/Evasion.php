<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

final class Evasion extends Ability
{
    public function evades(): bool
    {
        return true;
    }
}
