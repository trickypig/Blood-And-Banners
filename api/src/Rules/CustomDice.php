<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules;

use InvalidArgumentException;

final class CustomDice
{
    public function roll(int $count, RandomSource $random): array
    {
        if ($count < 0) {
            throw new InvalidArgumentException('Dice count cannot be negative.');
        }
        $faces = [];
        for ($index = 0; $index < $count; $index++) {
            $value = $random->roll(6);
            $faces[] = match (true) {
                $value <= 3 => 'sword',
                $value <= 5 => 'shield',
                default => 'blank',
            };
        }
        return [
            'faces' => $faces,
            'swords' => count(array_filter($faces, static fn(string $face): bool => $face === 'sword')),
            'shields' => count(array_filter($faces, static fn(string $face): bool => $face === 'shield')),
        ];
    }
}
