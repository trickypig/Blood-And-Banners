<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\CustomDice;
use BloodAndBanners\Rules\RandomSource;

final class IceMovement
{
    /** @return array{state:array,path:array,remainingMove:int,pending:?array} */
    public static function continue(array $state, string $unitId, int $remainingMove, RandomSource $random, array &$events): array
    {
        $condition = UnitConditions::named($state, $unitId, 'Slipping');
        $direction = $condition['data']['direction'] ?? null;
        $from = $state['units'][$unitId]['hex'];
        $path = [];
        $stopped = 'moveSpent';
        $damageEvents = [];
        $consequences = [];

        if (!is_array($direction) || count($direction) !== 2 || $from === null) {
            return ['state' => $state, 'path' => [], 'remainingMove' => $remainingMove, 'pending' => null];
        }

        while ($remainingMove > 0 && $state['phase'] === 'active' && $state['units'][$unitId]['hex'] !== null) {
            $here = $state['units'][$unitId]['hex'];
            if (Terrain::type($state['board'][$here]) !== 'ice') {
                $stopped = 'exit';
                break;
            }
            [$q, $r] = Hex::parse($here);
            $next = Hex::key($q + (int)$direction[0], $r + (int)$direction[1]);
            if (!isset($state['board'][$next])
                || self::occupantAt($state, $next) !== null
                || !Terrain::canEnter($state, $unitId, $here, $next, 'slip')) {
                $state = TerrainResolver::clearIce($state, $unitId, $events, false);
                $stopped = 'blocked';
                break;
            }
            $cost = Terrain::stepCost($state, $unitId, $here, $next, 'slip');
            if ($cost > $remainingMove) {
                $stopped = 'moveSpent';
                break;
            }

            $drop = max(0, $state['board'][$here]['elevation'] - $state['board'][$next]['elevation']);
            $resolved = TerrainResolver::enter($state, $unitId, $here, $next, 'slip', $direction, $random, $events);
            $state = $resolved['state'];
            $path[] = $next;
            $remainingMove -= $cost;
            if ($state['phase'] !== 'active' || $state['units'][$unitId]['hex'] === null) {
                $stopped = 'defeated';
                break;
            }

            for ($level = 0; $level < $drop; $level++) {
                $roll = (new CustomDice())->roll(1, $random);
                $damageEvents[] = MatchEngine::event('terrainDamage', [$next], [
                    'unitId' => $unitId,
                    'terrain' => 'fall',
                    'cause' => 'slipFall',
                    'dice' => 1,
                    'faces' => $roll['faces'],
                    'wounds' => $roll['swords'],
                    'hex' => $next,
                ]);
                $state = Wounds::apply($state, $unitId, $roll['swords'], $consequences);
                if ($state['phase'] !== 'active' || $state['units'][$unitId]['hex'] === null) {
                    $stopped = 'defeated';
                    break 2;
                }
            }

            if (Terrain::type($state['board'][$next]) !== 'ice') {
                $stopped = 'exit';
                break;
            }
        }

        $to = $state['units'][$unitId]['hex'] ?? ($path === [] ? $from : $path[array_key_last($path)]);
        $events[] = MatchEngine::event('unitSlid', array_values(array_unique([$from, ...$path])), [
            'unitId' => $unitId,
            'from' => $from,
            'to' => $to,
            'path' => $path,
            'direction' => array_values($direction),
            'stopped' => $stopped,
        ]);
        array_push($events, ...$damageEvents, ...$consequences);
        return ['state' => $state, 'path' => $path, 'remainingMove' => $remainingMove, 'pending' => null];
    }

    /** @return array{state:array,path:array,remainingMove:int,pending:?array} */
    public static function beforeActivation(array $state, string $unitId, RandomSource $random, array &$events): array
    {
        if (!UnitConditions::has($state, $unitId, 'Slipping')) {
            return [
                'state' => $state,
                'path' => [],
                'remainingMove' => Stats::effective($state, $unitId, 'move'),
                'pending' => null,
            ];
        }
        return self::continue($state, $unitId, Stats::effective($state, $unitId, 'move'), $random, $events);
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
