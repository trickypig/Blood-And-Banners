<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

final class FragileFocus extends Ability
{
    public function locksSpellsWhenWounded(): bool
    {
        return true;
    }
}
