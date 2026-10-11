<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

final class Board
{
    public static function fromScenario(array $definition): array
    {
        $board = [];
        foreach ($definition['map']['hexes'] as $hex) {
            $board[Hex::key((int)$hex['q'], (int)$hex['r'])] = [
                'elevation' => (int)$hex['elevation'],
                'sightBlocker' => (bool)($hex['sightBlocker'] ?? false),
                'playerStart' => isset($hex['playerStart']) ? (int)$hex['playerStart'] : null,
            ];
        }
        return $board;
    }

    public static function homes(array $board): array
    {
        $homes = [];
        foreach ($board as $key => $hex) {
            if ($hex['playerStart'] !== null) {
                $homes[$hex['playerStart']] = (string)$key;
            }
        }
        ksort($homes);
        return $homes;
    }
}
