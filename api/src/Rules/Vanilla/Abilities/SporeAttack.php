<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

final class SporeAttack extends SpecialAttack
{
    public function __construct(string $name, private readonly int $radius, private readonly int $threshold, private readonly bool $sacrifice = false)
    {
        parent::__construct($name);
    }

    public function targets(array $state, string $unitId): array
    {
        return [$unitId];
    }

    public function steps(array $state, string $unitId, array $targetIds, array $options = []): array
    {
        return [['op' => 'spores', 'unitId' => $unitId, 'ability' => $this->name, 'radius' => $this->radius, 'threshold' => $this->threshold, 'sacrifice' => $this->sacrifice]];
    }
}
