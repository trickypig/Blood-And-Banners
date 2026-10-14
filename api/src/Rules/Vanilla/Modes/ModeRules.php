<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Modes;

use BloodAndBanners\Rules\RandomSource;

/** What differs between game modes: start, the phases before battle, who acts next, activation limits and victory. */
interface ModeRules
{
    /** @return array{state: array, events: list<array>} */
    public function start(array $seatIds, array $board, RandomSource $random, array $options): array;

    /** Commands of the phases before battle (setup, draft). @return array{state: array, events: list<array>} */
    public function applyPreBattle(array $state, string $seatId, string $kind, array $payload): array;

    public function legalPreBattle(array $state, string $seatId): array;

    /** Turn slots in one round; slot 0 opens the round. */
    public function slotCount(array $state): int;

    public function seatAtSlot(array $state, int $slot): string;

    /** False when the slot's turn is skipped this time round. */
    public function slotLive(array $state, int $slot): bool;

    public function allowance(array $state, string $seatId): int;

    /** The mode's reason $unitId may not activate now, or null. */
    public function activationError(array $state, string $unitId): ?string;

    /** The match result once a side has won, or null. */
    public function victory(array $state): ?array;
}
