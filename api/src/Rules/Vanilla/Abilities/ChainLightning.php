<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Targeting;

/** Power 3 against 1–3 enemies in order; the first needs sight within 5, and all hops together cover at most 5 hexes. */
final class ChainLightning extends SpecialAttack
{
    public const REACH = 5;
    private const POWER = 3;
    private const MAX_TARGETS = 3;

    public function targets(array $state, string $unitId): array
    {
        return Targeting::enemiesInSight($state, $unitId, self::REACH);
    }

    public function validate(array $state, string $unitId, array $targetIds, array $options = []): ?string
    {
        $count = count($targetIds);
        if ($count < 1 || $count > self::MAX_TARGETS || count(array_unique($targetIds)) !== $count) {
            return 'invalidAbilityTarget';
        }
        if (!in_array($targetIds[0], $this->targets($state, $unitId), true)) {
            return 'invalidAbilityTarget';
        }
        $seatId = $state['units'][$unitId]['seatId'];
        $from = $state['units'][$unitId]['hex'];
        $total = 0;
        foreach ($targetIds as $targetId) {
            $target = $state['units'][$targetId] ?? null;
            if ($target === null || $target['hex'] === null || $target['seatId'] === $seatId) {
                return 'invalidAbilityTarget';
            }
            $total += Hex::distance($from, $target['hex']);
            $from = $target['hex'];
        }
        return $total > self::REACH ? 'chainTooLong' : null;
    }

    public function steps(array $state, string $unitId, array $targetIds, array $options = []): array
    {
        return array_map(fn(string $targetId): array => StepRunner::strike($unitId, $targetId, self::POWER, $this->name), $targetIds);
    }
}
