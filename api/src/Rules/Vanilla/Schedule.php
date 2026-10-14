<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\Vanilla\Modes\Modes;

/** Turn order as numbered slots per round; skipped slots take no turn and no turn number. */
final class Schedule
{
    public static function slotOf(array $state, string $seatId): int
    {
        $rules = Modes::ofState($state);
        for ($slot = 0; $slot < $rules->slotCount($state); $slot++) {
            if ($rules->seatAtSlot($state, $slot) === $seatId) {
                return $slot;
            }
        }
        return 0;
    }

    /** The next slot after $slot that takes a turn, wrapping into the next round. */
    public static function after(array $state, int $slot): int
    {
        $rules = Modes::ofState($state);
        $count = $rules->slotCount($state);
        for ($step = 1; $step <= $count; $step++) {
            $candidate = ($slot + $step) % $count;
            if ($rules->slotLive($state, $candidate)) {
                return $candidate;
            }
        }
        return $slot;
    }

    /** The turn number on which $seatId next starts a turn. */
    public static function nextTurnNumberOf(array $state, string $seatId): int
    {
        $turn = $state['turn'];
        if ($turn['activeSeatId'] === null) {
            return $turn['number'] + 1;
        }
        $rules = Modes::ofState($state);
        $slot = $turn['slot'] ?? self::slotOf($state, $turn['activeSeatId']);
        $count = $rules->slotCount($state);
        for ($step = 1; $step <= $count; $step++) {
            $slot = self::after($state, $slot);
            if ($rules->seatAtSlot($state, $slot) === $seatId) {
                return $turn['number'] + $step;
            }
        }
        return $turn['number'] + $count + 1;
    }
}
