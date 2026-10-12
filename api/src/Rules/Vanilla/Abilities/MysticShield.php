<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Effects;
use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Targeting;

final class MysticShield extends SpecialAttack
{
    public function targets(array $state, string $unitId): array
    {
        return Targeting::alliesWithin($state, $unitId, 4, true);
    }

    public function steps(array $state, string $unitId, array $targetIds): array
    {
        return [StepRunner::effect($this->name, $unitId, $targetIds[0], 'defense', 3, $state['units'][$unitId]['seatId'], Effects::UNTIL_NEXT_TURN)];
    }
}
