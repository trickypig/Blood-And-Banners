<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\StepRunner;

/** Range 5, save 15+ or be Charmed through the target owner's next turn. */
final class SirensSong extends SaveAttack
{
    public function __construct(string $name)
    {
        parent::__construct($name, range: 5, threshold: 15);
    }

    public function failureSteps(array $state, string $unitId, string $targetId): array
    {
        return [StepRunner::condition('Charmed', $targetId, $unitId, [
            'kind' => 'turnEnd', 'seatId' => $state['units'][$targetId]['seatId'], 'afterTurnNumber' => $state['turn']['number'],
        ])];
    }
}
