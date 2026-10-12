<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

final class MoonlightGlare extends Ability
{
    public function offersReroll(): bool
    {
        return true;
    }
}
