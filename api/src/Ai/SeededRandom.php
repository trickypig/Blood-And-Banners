<?php
declare(strict_types=1);

namespace BloodAndBanners\Ai;

use BloodAndBanners\Rules\RandomSource;

/** Deterministic dice (xorshift32) so simulated outcomes repeat per seed. */
final class SeededRandom implements RandomSource
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = ($seed * 2654435761 + 1013904223) & 0xFFFFFFFF;
        if ($this->state === 0) {
            $this->state = 0x9E3779B9;
        }
    }

    public function roll(int $sides): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->state = $x;
        return ($x % max(1, $sides)) + 1;
    }
}
