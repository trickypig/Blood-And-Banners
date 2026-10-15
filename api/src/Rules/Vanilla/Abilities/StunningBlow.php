<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Conditions\Stunned;

/** After each hit, the target saves on 11+ or is Stunned until the end of its seat's next turn. */
final class StunningBlow extends Ability
{
    public function afterHit(array $state, string $ownerId, string $targetId, int $wounds = 0): array
    {
        return [StepRunner::save($targetId, $ownerId, $this->name)];
    }

    public function onFailedSave(array $state, string $ownerId, string $targetId, int $roll, RandomSource $random, array &$events): array
    {
        return Stunned::apply($state, $targetId, $ownerId, $events);
    }
}
