<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

/** Cannot be pushed or knocked back by any effect. */
final class Unmovable extends Ability
{
    public function resistsForcedMovement(): bool
    {
        return true;
    }
}
