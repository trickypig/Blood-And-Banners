<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\CustomDice;
use BloodAndBanners\Rules\RandomSource;

/** Forced movement: pushes (with collisions, walls, edges and falls) and teleport destinations. Never triggers disengagement. */
final class Forced
{
    private const TELEPORT_REACH = 3;

    public static function directionFrom(string $fromHex, string $toHex): ?array
    {
        [$q1, $r1] = Hex::parse($fromHex);
        [$q2, $r2] = Hex::parse($toHex);
        $direction = [$q2 - $q1, $r2 - $r1];
        return in_array($direction, Hex::DIRECTIONS, true) ? $direction : null;
    }

    public static function push(array $state, string $unitId, array $direction, int $hexes, ?string $sourceUnitId, RandomSource $random, array &$events): array
    {
        $dice = new CustomDice();
        $from = $state['units'][$unitId]['hex'];
        $path = [$from];
        $collisions = [];
        $falls = [];
        $blocked = null;
        $consequences = [];
        for ($step = 0; $step < $hexes && $state['phase'] === 'active'; $step++) {
            $here = $state['units'][$unitId]['hex'];
            if ($here === null) {
                break;
            }
            [$q, $r] = Hex::parse($here);
            $next = Hex::key($q + $direction[0], $r + $direction[1]);
            $hex = $state['board'][$next] ?? null;
            if ($hex === null || $hex['sightBlocker'] || $hex['elevation'] - $state['board'][$here]['elevation'] >= 2) {
                $blocked = $hex === null ? 'edge' : 'wall';
                $roll = $dice->roll(1, $random);
                $collisions[] = ['unitId' => $unitId, 'faces' => $roll['faces'], 'wounds' => $roll['swords']];
                $state = Wounds::apply($state, $unitId, $roll['swords'], $consequences);
                break;
            }
            $occupant = self::occupantAt($state, $next);
            if ($occupant !== null) {
                $blocked = 'unit';
                $rolls = [];
                foreach ([$unitId, $occupant] as $hitId) {
                    $roll = $dice->roll(1, $random);
                    $rolls[$hitId] = $roll['swords'];
                    $collisions[] = ['unitId' => $hitId, 'faces' => $roll['faces'], 'wounds' => $roll['swords']];
                }
                foreach ($rolls as $hitId => $wounds) {
                    $state = Wounds::apply($state, (string)$hitId, $wounds, $consequences);
                }
                break;
            }
            $drop = $state['board'][$here]['elevation'] - $hex['elevation'];
            $state['units'][$unitId]['hex'] = $next;
            $path[] = $next;
            if ($drop >= 2) {
                $roll = $dice->roll($drop, $random);
                $falls[] = ['levels' => $drop, 'faces' => $roll['faces'], 'wounds' => $roll['swords']];
                $state = Wounds::apply($state, $unitId, $roll['swords'], $consequences);
            }
        }
        $events[] = MatchEngine::event('unitPushed', $path, [
            'unitId' => $unitId,
            'sourceUnitId' => $sourceUnitId,
            'from' => $from,
            'to' => $path[count($path) - 1],
            'path' => $path,
            'collisions' => $collisions,
            'falls' => $falls,
            'blocked' => $blocked,
        ]);
        foreach ($consequences as $event) {
            $events[] = $event;
        }
        return $state;
    }

    /** Empty, walkable hexes the unit could be teleported to. */
    public static function teleportDestinations(array $state, string $unitId): array
    {
        $origin = $state['units'][$unitId]['hex'];
        $elevation = $state['board'][$origin]['elevation'];
        $destinations = [];
        foreach ($state['board'] as $key => $hex) {
            $key = (string)$key;
            if ($key === $origin || $hex['sightBlocker'] || self::occupantAt($state, $key) !== null) {
                continue;
            }
            if (Hex::distance($origin, $key) + intdiv(abs($hex['elevation'] - $elevation), 2) <= self::TELEPORT_REACH) {
                $destinations[] = $key;
            }
        }
        sort($destinations, SORT_STRING);
        return $destinations;
    }

    private static function occupantAt(array $state, string $hex): ?string
    {
        foreach ($state['units'] as $unitId => $unit) {
            if ($unit['hex'] === $hex) {
                return (string)$unitId;
            }
        }
        return null;
    }
}
