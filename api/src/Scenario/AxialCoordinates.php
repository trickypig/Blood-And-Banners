<?php
declare(strict_types=1);

namespace BloodAndBanners\Scenario;

final class AxialCoordinates
{
    /** @return array{q:int, r:int} */
    public static function fromOddColumnOffset(int $column, int $row): array
    {
        return [
            'q' => $row - intdiv($column, 2),
            'r' => $column,
        ];
    }
}
