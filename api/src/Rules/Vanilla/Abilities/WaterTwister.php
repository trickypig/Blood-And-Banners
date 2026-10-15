<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Combat;
use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\Sides;
use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Water;

final class WaterTwister extends SpecialAttack
{
    private function origin(array $state, string $unitId, string $targetId): ?string
    {
        $origins = Water::nearby($state, $state['units'][$unitId]['hex']);
        $targetHex = $state['units'][$targetId]['hex'];
        usort($origins, static fn(string $a, string $b): int => [Hex::distance($a, $targetHex), $a] <=> [Hex::distance($b, $targetHex), $b]);
        foreach ($origins as $hex) {
            if (Hex::distance($hex, $state['units'][$targetId]['hex']) <= 3
                && Combat::visible($state, $hex, $state['units'][$targetId]['hex'])
                && !Combat::protectedFrom($state, $unitId, $targetId, $hex)) {
                return $hex;
            }
        }
        return null;
    }

    public function targets(array $state, string $unitId): array
    {
        $ids = array_values(array_filter(array_map('strval', array_keys($state['units'])), fn(string $id): bool =>
            $state['units'][$id]['hex'] !== null && Sides::enemies($state, $unitId, $id) && $this->origin($state, $unitId, $id) !== null
        ));
        sort($ids, SORT_STRING);
        return $ids;
    }

    public function steps(array $state, string $unitId, array $targetIds, array $options = []): array
    {
        return [StepRunner::strike($unitId, $targetIds[0], 4, $this->name, false, 'water', $this->origin($state, $unitId, $targetIds[0]))];
    }
}
