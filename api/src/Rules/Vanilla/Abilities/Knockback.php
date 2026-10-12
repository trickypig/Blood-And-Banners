<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\Forced;
use BloodAndBanners\Rules\Vanilla\StepRunner;

/** After each hit, the target saves on 11+ or is pushed 1 hex away (2 on a natural 1). */
final class Knockback extends Ability
{
    public function afterHit(array $state, string $ownerId, string $targetId): array
    {
        return [StepRunner::save($targetId, $ownerId, $this->name)];
    }

    public function onFailedSave(array $state, string $ownerId, string $targetId, int $roll, RandomSource $random, array &$events): array
    {
        $direction = Forced::directionFrom($state['units'][$ownerId]['hex'], $state['units'][$targetId]['hex']);
        if ($direction === null) {
            return $state;
        }
        return Forced::push($state, $targetId, $direction, $roll === 1 ? 2 : 1, $ownerId, $random, $events);
    }
}
