<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules;

use InvalidArgumentException;

final class SecureRandomSource implements RandomSource
{
    public function roll(int $sides): int
    {
        if ($sides < 2) {
            throw new InvalidArgumentException('A die must have at least two sides.');
        }
        return random_int(1, $sides);
    }
}
