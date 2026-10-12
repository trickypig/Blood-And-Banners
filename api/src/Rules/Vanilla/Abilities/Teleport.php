<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Conditions\Condition;
use BloodAndBanners\Rules\Vanilla\Forced;
use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Targeting;
use BloodAndBanners\Rules\Vanilla\UnitConditions;

/** Move a friendly unit within 3 hexes (Malachar included, unless he is stunned) to an empty hex within 3 (2 levels count as 1 hex). */
final class Teleport extends SpecialAttack
{
    private const REACH = 3;

    public function targets(array $state, string $unitId): array
    {
        return array_values(array_filter(
            Targeting::alliesWithin($state, $unitId, self::REACH, true),
            static fn(string $id): bool => Forced::teleportDestinations($state, $id) !== []
                && !($id === $unitId && UnitConditions::any($state, $id, static fn(Condition $condition): bool => $condition->blocksMovement()))
        ));
    }

    public function validate(array $state, string $unitId, array $targetIds, array $options = []): ?string
    {
        if (count($targetIds) !== 1 || !in_array($targetIds[0], $this->targets($state, $unitId), true)) {
            return 'invalidAbilityTarget';
        }
        return in_array($options['toHex'] ?? '', Forced::teleportDestinations($state, $targetIds[0]), true) ? null : 'invalidDestination';
    }

    public function steps(array $state, string $unitId, array $targetIds, array $options = []): array
    {
        return [StepRunner::teleport($unitId, $targetIds[0], $options['toHex'])];
    }
}
