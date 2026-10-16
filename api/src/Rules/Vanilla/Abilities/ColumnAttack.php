<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Targeting;

/** Treant's reusable swing and one-use toss share the same physical column. */
final class ColumnAttack extends SpecialAttack
{
    public function __construct(string $name, private readonly bool $toss = false)
    {
        parent::__construct($name);
    }

    public function unavailable(array $state, string $unitId): ?string
    {
        return !empty($state['units'][$unitId]['flags']['columnTossed']) ? 'columnAlreadyTossed' : null;
    }

    public function onUse(array $state, string $unitId): array
    {
        if ($this->toss) {
            // Spend the column when the attack starts, even if it is evaded or waits for a choice.
            $state['units'][$unitId]['flags']['columnTossed'] = true;
        }
        return $state;
    }

    public function targets(array $state, string $unitId): array
    {
        if ($this->unavailable($state, $unitId) !== null) {
            return [];
        }
        $targets = Targeting::enemiesInSight($state, $unitId, $this->toss ? 4 : 2);
        return $this->toss
            ? array_values(array_filter($targets, static fn(string $id): bool => Hex::distance($state['units'][$unitId]['hex'], $state['units'][$id]['hex']) > 2))
            : $targets;
    }

    public function steps(array $state, string $unitId, array $targetIds, array $options = []): array
    {
        return [StepRunner::strike($unitId, $targetIds[0], $this->toss ? 7 : 5, $this->name)];
    }
}
