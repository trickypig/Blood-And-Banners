<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

final class OneLastHorn extends Ability
{
    public function survivesDefeatOnce(): bool
    {
        return true;
    }
}
