<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Targeting;

/** A special attack whose saving throw precedes its effects or damage. */
abstract class SaveAttack extends SpecialAttack
{
    public function __construct(string $name, public readonly int $range, public readonly int $threshold)
    {
        parent::__construct($name);
    }

    public function targets(array $state, string $unitId): array
    {
        return Targeting::enemiesInSight($state, $unitId, $this->range);
    }

    public function steps(array $state, string $unitId, array $targetIds, array $options = []): array
    {
        return [['op' => 'saveAttack', 'attackerId' => $unitId, 'targetId' => $targetIds[0], 'ability' => $this->name]];
    }

    abstract public function failureSteps(array $state, string $unitId, string $targetId): array;
}
