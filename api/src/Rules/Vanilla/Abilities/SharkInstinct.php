<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

final class SharkInstinct extends Ability
{
    public function statDelta(array $state, string $ownerId, string $unitId, string $stat, array $context): int
    {
        $target = $state['units'][$context['defenderId'] ?? ''] ?? null;
        return $stat === 'power' && $ownerId === $unitId
            && ($context['attackerId'] ?? null) === $ownerId
            && $target !== null && $target['hex'] !== null && $target['wounds'] > 0 ? 1 : 0;
    }
}
