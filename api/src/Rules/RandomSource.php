<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules;

interface RandomSource
{
    public function roll(int $sides): int;
}
