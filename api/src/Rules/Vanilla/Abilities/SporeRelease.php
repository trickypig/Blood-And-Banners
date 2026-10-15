<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\Sides;
use BloodAndBanners\Rules\Vanilla\Spores;

final class SporeRelease extends Ability
{
    public function onDefeat(array $state, string $unitId, array $defeated, ?string $attackerId, ?RandomSource $random, array &$events): array
    {
        if ($defeated['name'] === 'Myconid') {
            $targets = Spores::enemiesWithin($state, $unitId, $defeated['hex'], 2);
        } else {
            $killer = $state['units'][$attackerId ?? ''] ?? null;
            $targets = $killer !== null && $killer['hex'] !== null && Sides::enemies($state, $unitId, $attackerId)
                && Hex::distance($defeated['hex'], $killer['hex']) === 1 ? [$attackerId] : [];
        }
        if ($targets === []) {
            return $state;
        }
        if ($random === null) {
            throw new \LogicException('A spore death reaction requires a random source.');
        }
        return Spores::release($state, $unitId, $defeated['hex'], $targets, $this->name, 10, $random, $events);
    }
}
