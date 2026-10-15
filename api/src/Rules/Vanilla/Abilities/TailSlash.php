<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\Forced;
use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Targeting;

final class TailSlash extends SpecialAttack
{
    public function attacksPerActivation(array $unit = [], array $context = []): int
    {
        // A bite is the only normal attack; spending Tail Slash opens the second total attack slot.
        return !empty($context['specialAttack']) || in_array($this->name, $context['abilitiesUsed'] ?? [], true) ? 2 : 1;
    }

    public function unavailable(array $state, string $unitId): ?string
    {
        return in_array($this->name, $state['turn']['abilitiesUsed'][$unitId] ?? [], true) ? 'abilityAlreadyUsed' : null;
    }

    public function onUse(array $state, string $unitId): array
    {
        $state['turn']['abilitiesUsed'][$unitId][] = $this->name;
        return $state;
    }

    public function targets(array $state, string $unitId): array
    {
        return Targeting::enemiesInSight($state, $unitId, 1);
    }

    public function steps(array $state, string $unitId, array $targetIds, array $options = []): array
    {
        return [StepRunner::strike($unitId, $targetIds[0], 4, $this->name)];
    }

    public function afterStrike(array $state, string $unitId, string $targetId, int $wounds): array
    {
        return [StepRunner::save($targetId, $unitId, $this->name)];
    }

    public function onFailedSave(array $state, string $ownerId, string $targetId, int $roll, RandomSource $random, array &$events): array
    {
        $direction = Forced::directionFrom($state['units'][$ownerId]['hex'], $state['units'][$targetId]['hex']);
        return $direction === null ? $state : Forced::push($state, $targetId, $direction, 1, $ownerId, $random, $events);
    }
}
