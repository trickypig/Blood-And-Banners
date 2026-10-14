<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Turn;

/** A battle where seat-a and seat-c are allied heroes and seat-b is the Void. */
function allied_battle(array $board, array $units, string $activeSeat = 'seat-a'): array
{
    $state = vanilla_battle($board, [], $activeSeat);
    $state['seats'] = ['seat-a', 'seat-b', 'seat-c'];
    $state['sides'] = [
        'heroes' => ['kind' => 'heroes', 'seatIds' => ['seat-a', 'seat-c']],
        'void' => ['kind' => 'void', 'seatIds' => ['seat-b']],
    ];
    foreach ($units as $unit) {
        $unit['side'] ??= $unit['seatId'] === 'seat-b' ? 'void' : 'heroes';
        $state['units'][$unit['unitId']] = $unit;
    }
    $state['turn'] = Turn::fresh($state, $activeSeat, 1);
    return $state;
}
