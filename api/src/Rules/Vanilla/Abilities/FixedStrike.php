<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Targeting;

/** A plain strike with a printed Power and Range (Bolt of Annihilation, Flame Strike). */
final class FixedStrike extends SpecialAttack
{
    public function __construct(string $name, private readonly int $power, private readonly int $range)
    {
        parent::__construct($name);
    }

    public function targets(array $state, string $unitId): array
    {
        return Targeting::enemiesInSight($state, $unitId, $this->range);
    }

    public function steps(array $state, string $unitId, array $targetIds, array $options = []): array
    {
        return [StepRunner::strike($unitId, $targetIds[0], $this->power, $this->name)];
    }
}
