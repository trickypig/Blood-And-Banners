<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\Poison;
use BloodAndBanners\Rules\Vanilla\StepRunner;

final class PoisonAttack extends Ability
{
    public function __construct(
        string $name,
        private readonly int $saveThreshold,
        private readonly bool $automaticOnWound = false,
        private readonly bool $immediate = false
    ) {
        parent::__construct($name);
    }

    public function afterHit(array $state, string $ownerId, string $targetId, int $wounds = 0): array
    {
        return $this->automaticOnWound && $wounds > 0
            ? [StepRunner::poison($targetId, $ownerId, $this->immediate)]
            : [StepRunner::save($targetId, $ownerId, $this->name, $this->saveThreshold)];
    }

    public function onFailedSave(array $state, string $ownerId, string $targetId, int $roll, RandomSource $random, array &$events): array
    {
        return Poison::apply($state, $targetId, $ownerId, $random, $events, $this->immediate);
    }
}
