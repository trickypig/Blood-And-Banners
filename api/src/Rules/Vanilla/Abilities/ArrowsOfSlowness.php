<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\StepRunner;

final class ArrowsOfSlowness extends Ability
{
    public function afterHit(array $state, string $ownerId, string $targetId, int $wounds = 0): array
    {
        $seatId = $state['units'][$targetId]['seatId'];
        return $wounds > 0 ? [StepRunner::condition(
            'Slowed', $targetId, $ownerId, ['kind' => 'turnEnd', 'seatId' => $seatId]
        )] : [];
    }
}
