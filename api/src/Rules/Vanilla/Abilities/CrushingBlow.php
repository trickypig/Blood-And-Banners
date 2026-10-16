<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

/** Normal attacks gain Power equal to half the target's printed Defense, rounded up; temporary Defense changes don't count. */
final class CrushingBlow extends Ability
{
    public function statDelta(array $state, string $ownerId, string $unitId, string $stat, array $context): int
    {
        $target = $state['units'][$context['defenderId'] ?? ''] ?? null;
        if ($stat !== 'power' || $ownerId !== $unitId || ($context['attackerId'] ?? null) !== $ownerId
            || $target === null || ($context['ability'] ?? null) !== null) {
            return 0;
        }
        return intdiv((int)$target['defense'] + 1, 2);
    }
}
