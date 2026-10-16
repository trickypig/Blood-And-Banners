<?php
declare(strict_types=1);
namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\Sides;
use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\UnitConditions;

final class PanPipes extends SpecialAttack
{
    public function targets(array $state, string $unitId): array
    {
        return $state['units'][$unitId]['hex'] === null ? [] : [$unitId];
    }

    public function steps(array $state, string $unitId, array $targetIds, array $options = []): array
    {
        return [StepRunner::condition($this->name, $unitId, $unitId, ['kind' => 'turnStart', 'seatId' => $state['units'][$unitId]['seatId']])];
    }

    /** Only direct targeting of an enemy Satyr invokes the pipes; overlapping auras do not stack. */
    public static function protector(array $state, string $attackerId, string $targetId): ?string
    {
        $target = $state['units'][$targetId];
        if ($target['hex'] === null || !str_starts_with($target['name'], 'Satyr') || !Sides::enemies($state, $attackerId, $targetId)) {
            return null;
        }
        $ids = array_map('strval', array_keys($state['units']));
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            $unit = $state['units'][$id];
            if ($unit['hex'] !== null && UnitConditions::has($state, $id, 'Pan Pipes')
                && Sides::allies($state, $id, $targetId) && Hex::distance($unit['hex'], $target['hex']) <= 3) {
                return $id;
            }
        }
        return null;
    }
}
