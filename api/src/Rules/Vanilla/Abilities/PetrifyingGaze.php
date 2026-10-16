<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\StepRunner;

final class PetrifyingGaze extends SaveAttack
{
    public function __construct(string $name)
    {
        parent::__construct($name, range: 3, threshold: 13);
    }

    public function failureSteps(array $state, string $unitId, string $targetId): array
    {
        return [StepRunner::condition('Petrified', $targetId, $unitId, [
            'kind' => 'turnStart', 'seatId' => $state['units'][$unitId]['seatId'],
        ])];
    }
}
