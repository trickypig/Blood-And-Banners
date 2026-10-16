<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\Conditions\Condition;
use BloodAndBanners\Rules\Vanilla\Forced;
use BloodAndBanners\Rules\Vanilla\Sides;
use BloodAndBanners\Rules\Vanilla\Targeting;
use BloodAndBanners\Rules\Vanilla\UnitConditions;

/** Instead of moving, push an adjacent enemy whose Move is above 2 back 2 hexes. */
final class GustOfWind extends TargetedAbility
{
    private const PUSH = 2;

    public function unavailable(array $state, string $unitId): ?string
    {
        if (UnitConditions::any($state, $unitId, static fn(Condition $condition): bool => $condition->blocksMovement())) {
            return 'unitStunned';
        }
        $turn = $state['turn'];
        if (!in_array($unitId, $turn['activated'], true)) {
            return null;
        }
        $still = $turn['activeUnitId'] === $unitId && !$turn['moved'] && $turn['moveSpent'] === 0 && $turn['attacks'] === 0;
        return $still ? null : 'gustNeedsStillness';
    }

    public function targets(array $state, string $unitId): array
    {
        return array_values(array_filter(
            Targeting::unitsWithin($state, $unitId, 1, false),
            static fn(string $id): bool => Sides::enemies($state, $unitId, $id) && (int)$state['units'][$id]['move'] > 2 && !Forced::immovable($state, $id)
        ));
    }

    public function apply(array $state, string $unitId, string $targetId, RandomSource $random, array &$events): array
    {
        $state['turn']['moved'] = true;
        $direction = Forced::directionFrom($state['units'][$unitId]['hex'], $state['units'][$targetId]['hex']);
        return Forced::push($state, $targetId, $direction, self::PUSH, $unitId, $random, $events);
    }
}
