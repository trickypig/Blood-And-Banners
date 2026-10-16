<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\StepRunner;

final class StoneGrip extends SaveAttack
{
    public function __construct(string $name)
    {
        parent::__construct($name, range: 1, threshold: 12);
    }

    public function failureSteps(array $state, string $unitId, string $targetId): array
    {
        return [
            StepRunner::condition('Entrapped', $targetId, $unitId, [
                'kind' => 'turnEnd', 'seatId' => $state['units'][$targetId]['seatId'], 'afterTurnNumber' => $state['turn']['number'],
            ]),
            StepRunner::strike($unitId, $targetId, 2, $this->name) + ['skipEvasion' => true],
        ];
    }
}
