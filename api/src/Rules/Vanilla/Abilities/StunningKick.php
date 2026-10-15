<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\Conditions\Stunned;
use BloodAndBanners\Rules\Vanilla\StepRunner;

final class StunningKick extends Ability
{
    public function afterHit(array $state, string $ownerId, string $targetId, int $wounds = 0): array
    {
        return $wounds > 0 ? [StepRunner::save($targetId, $ownerId, $this->name, 10)] : [];
    }

    public function onFailedSave(array $state, string $ownerId, string $targetId, int $roll, RandomSource $random, array &$events): array
    {
        return Stunned::apply($state, $targetId, $ownerId, $events, 1);
    }
}
