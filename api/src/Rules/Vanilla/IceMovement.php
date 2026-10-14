<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Game\GameServiceException;
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
            if (!isset($state['board'][$next]) || !Terrain::canEnter($state, $unitId, $here, $next, 'slip')) {
                $state = TerrainResolver::clearIce($state, $unitId, $events, false);
                $stopped = 'blocked';
                break;
            }
            $occupantId = self::occupantAt($state, $next);
            if ($occupantId !== null) {
                if (Sides::enemies($state, $unitId, $occupantId)) {
                    return self::enemyCollision(
                        $state, $unitId, $occupantId, $from, $path, $direction, $remainingMove,
                        $random, $events, $damageEvents, $consequences
                    );
                }
                return self::openFriendlyChoice(
                    $state, $unitId, $occupantId, $from, $path, $direction, $remainingMove,
                    $events, $damageEvents, $consequences
                );
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
        $result = self::continue($state, $unitId, Stats::effective($state, $unitId, 'move'), $random, $events);
        if ($result['state']['pending'] !== null) {
            $result['state']['pending']['context']['activateAfter'] = true;
            $result['pending'] = $result['state']['pending'];
        }
        return $result;
    }

    public static function resolveChoice(array $state, string $option, RandomSource $random, array &$events): array
    {
        $pending = $state['pending'] ?? null;
        $choice = $pending['choice'] ?? null;
        $context = $pending['context'] ?? null;
        if (($choice['kind'] ?? null) !== 'iceCollision' || !is_array($context)) {
            self::invalidChoice();
        }
        $unitId = (string)$choice['unitId'];
        $current = (string)($context['currentHex'] ?? '');
        $direction = $context['direction'] ?? null;
        $remainingMove = (int)($context['remainingMove'] ?? -1);
        $path = array_values($context['path'] ?? []);
        $from = (string)($context['from'] ?? $current);
        $blockerId = (string)($context['blockerUnitId'] ?? '');
        $activateAfter = (bool)($context['activateAfter'] ?? false);
        $slipping = UnitConditions::named($state, $unitId, 'Slipping');
        if (!isset($state['units'][$unitId], $state['units'][$blockerId])
            || $state['units'][$unitId]['hex'] !== $current
            || !is_array($direction)
            || count($direction) !== 2
            || ($slipping['data']['direction'] ?? null) !== $direction) {
            self::invalidChoice();
        }
        $blockerHex = self::forwardHex($current, $direction);
        if ($state['units'][$blockerId]['hex'] !== $blockerHex
            || Sides::enemies($state, $unitId, $blockerId)) {
            self::invalidChoice();
        }

        $state['pending'] = null;
        $events[] = MatchEngine::event('choiceResolved', [], ['choiceId' => $choice['choiceId'], 'option' => $option]);
        if ($option === 'stop') {
            $state = TerrainResolver::clearIce($state, $unitId, $events, false);
            self::slideEvent($events, $unitId, $from, $current, $path, $direction, 'friendlyStop');
            return [
                'state' => $state, 'path' => $path, 'remainingMove' => $remainingMove,
                'pending' => null, 'activateAfter' => $activateAfter,
            ];
        }

        $legal = self::friendlyOptions($state, $unitId, $current, $blockerHex, $direction, $remainingMove);
        if (!in_array($option, $legal['options'], true)) {
            self::invalidChoice();
        }
        $target = $option === 'continue'
            ? self::forwardHex($blockerHex, $direction)
            : (str_starts_with($option, 'swing:') ? substr($option, 6) : '');
        if ($target === '') {
            self::invalidChoice();
        }
        [$blockerQ, $blockerR] = Hex::parse($blockerHex);
        [$targetQ, $targetR] = Hex::parse($target);
        $newDirection = [$targetQ - $blockerQ, $targetR - $blockerR];

        $cost = Terrain::stepCost($state, $unitId, $current, $blockerHex, 'slip');
        $drop = max(0, $state['board'][$current]['elevation'] - $state['board'][$blockerHex]['elevation']);
        $resolved = TerrainResolver::enter($state, $unitId, $current, $blockerHex, 'slip', $direction, $random, $events);
        $state = $resolved['state'];
        $path[] = $blockerHex;
        $remainingMove -= $cost;
        $state = self::resolveFall($state, $unitId, $blockerHex, $drop, $random, $events);
        if ($state['phase'] !== 'active' || $state['units'][$unitId]['hex'] === null) {
            self::slideEvent($events, $unitId, $from, $blockerHex, $path, $direction, 'defeated');
            return [
                'state' => $state, 'path' => $path, 'remainingMove' => $remainingMove,
                'pending' => null, 'activateAfter' => $activateAfter,
            ];
        }

        if ($option !== 'continue' && UnitConditions::has($state, $unitId, 'Slipping')) {
            $state = UnitConditions::updateData($state, $unitId, 'Slipping', ['direction' => $newDirection]);
        }
        $cost = Terrain::stepCost($state, $unitId, $blockerHex, $target, 'slip');
        $drop = max(0, $state['board'][$blockerHex]['elevation'] - $state['board'][$target]['elevation']);
        $resolved = TerrainResolver::enter($state, $unitId, $blockerHex, $target, 'slip', $newDirection, $random, $events);
        $state = $resolved['state'];
        $path[] = $target;
        $remainingMove -= $cost;
        $state = self::resolveFall($state, $unitId, $target, $drop, $random, $events);
        if ($state['phase'] !== 'active' || $state['units'][$unitId]['hex'] === null) {
            self::slideEvent($events, $unitId, $from, $target, $path, $newDirection, 'defeated');
            return [
                'state' => $state, 'path' => $path, 'remainingMove' => $remainingMove,
                'pending' => null, 'activateAfter' => $activateAfter,
            ];
        }
        if (Terrain::type($state['board'][$target]) !== 'ice') {
            self::slideEvent($events, $unitId, $from, $target, $path, $newDirection, 'exit');
            return [
                'state' => $state, 'path' => $path, 'remainingMove' => $remainingMove,
                'pending' => null, 'activateAfter' => $activateAfter,
            ];
        }
        $eventStart = count($events);
        $continued = self::continue($state, $unitId, $remainingMove, $random, $events);
        $continuedPath = [...$path, ...$continued['path']];
        $continued['path'] = $continuedPath;
        $continued['activateAfter'] = $activateAfter;
        if ($continued['state']['pending'] !== null) {
            $continued['state']['pending']['context']['from'] = $from;
            $continued['state']['pending']['context']['path'] = [
                ...$path,
                ...($continued['state']['pending']['context']['path'] ?? []),
            ];
            $continued['state']['pending']['context']['activateAfter'] = $activateAfter;
            $continued['pending'] = $continued['state']['pending'];
        } else {
            for ($index = $eventStart; $index < count($events); $index++) {
                if ($events[$index]['kind'] !== 'unitSlid') {
                    continue;
                }
                $events[$index]['focusHexes'] = array_values(array_unique([$from, ...$continuedPath]));
                $events[$index]['payload']['from'] = $from;
                $events[$index]['payload']['path'] = $continuedPath;
                break;
            }
        }
        return $continued;
    }

    private static function enemyCollision(
        array $state,
        string $unitId,
        string $occupantId,
        string $from,
        array $path,
        array $direction,
        int $remainingMove,
        RandomSource $random,
        array &$events,
        array $damageEvents,
        array $consequences
    ): array {
        $state = TerrainResolver::clearIce($state, $unitId, $events, false);
        foreach ([$unitId, $occupantId] as $hitId) {
            if ($state['phase'] !== 'active') {
                break;
            }
            $hex = $state['units'][$hitId]['hex'];
            $roll = (new CustomDice())->roll(1, $random);
            $damageEvents[] = MatchEngine::event('terrainDamage', [$hex], [
                'unitId' => $hitId, 'terrain' => 'ice', 'cause' => 'collision', 'dice' => 1,
                'faces' => $roll['faces'], 'wounds' => $roll['swords'], 'hex' => $hex,
            ]);
            $state = Wounds::apply($state, $hitId, $roll['swords'], $consequences);
        }
        $to = $state['units'][$unitId]['hex'] ?? ($path === [] ? $from : $path[array_key_last($path)]);
        self::slideEvent($events, $unitId, $from, $to, $path, $direction, 'enemyCollision');
        array_push($events, ...$damageEvents, ...$consequences);
        return ['state' => $state, 'path' => $path, 'remainingMove' => $remainingMove, 'pending' => null];
    }

    private static function openFriendlyChoice(
        array $state,
        string $unitId,
        string $occupantId,
        string $from,
        array $path,
        array $direction,
        int $remainingMove,
        array &$events,
        array $damageEvents,
        array $consequences
    ): array {
        $current = $state['units'][$unitId]['hex'];
        $blockerHex = $state['units'][$occupantId]['hex'];
        $legal = self::friendlyOptions($state, $unitId, $current, $blockerHex, $direction, $remainingMove);
        $state['choiceCounter'] = (int)($state['choiceCounter'] ?? 0) + 1;
        $choice = [
            'choiceId' => 'choice-' . $state['choiceCounter'],
            'seatId' => $state['units'][$unitId]['seatId'],
            'kind' => 'iceCollision',
            'unitId' => $unitId,
            'options' => $legal['options'],
            'targetHexes' => $legal['targetHexes'],
            'openedAt' => time(),
        ];
        $context = [
            'currentHex' => $current,
            'direction' => array_values($direction),
            'remainingMove' => $remainingMove,
            'path' => $path,
            'blockerUnitId' => $occupantId,
            'from' => $from,
            'activateAfter' => false,
        ];
        $state['pending'] = ['choice' => $choice, 'context' => $context];
        array_push($events, ...$damageEvents, ...$consequences);
        $events[] = MatchEngine::event('choiceOpened', [], [
            'choiceId' => $choice['choiceId'], 'seatId' => $choice['seatId'],
            'kind' => $choice['kind'], 'unitId' => $choice['unitId'],
        ]);
        return ['state' => $state, 'path' => $path, 'remainingMove' => $remainingMove, 'pending' => $state['pending']];
    }

    private static function friendlyOptions(array $state, string $unitId, string $current, string $blockerHex, array $direction, int $remainingMove): array
    {
        $options = [];
        $targetHexes = [];
        $forward = self::forwardHex($blockerHex, $direction);
        if (self::legalExit($state, $unitId, $current, $blockerHex, $forward, $remainingMove)) {
            $options[] = 'continue';
        }
        $options[] = 'stop';
        $neighbors = Hex::neighbors($blockerHex, $state['board']);
        sort($neighbors, SORT_STRING);
        foreach ($neighbors as $exit) {
            if ($exit === $current || $exit === $forward || !self::legalExit($state, $unitId, $current, $blockerHex, $exit, $remainingMove)) {
                continue;
            }
            $targetHexes[] = $exit;
            $options[] = 'swing:' . $exit;
        }
        return ['options' => $options, 'targetHexes' => $targetHexes];
    }

    private static function legalExit(array $state, string $unitId, string $current, string $blockerHex, string $target, int $remainingMove): bool
    {
        return $target !== $current
            && isset($state['board'][$target])
            && self::occupantAt($state, $target) === null
            && Terrain::canEnter($state, $unitId, $blockerHex, $target, 'slip')
            && self::exitCost($state, $unitId, $current, $blockerHex, $target) <= $remainingMove;
    }

    private static function exitCost(array $state, string $unitId, string $current, string $blockerHex, string $target): int
    {
        return Terrain::stepCost($state, $unitId, $current, $blockerHex, 'slip')
            + Terrain::stepCost($state, $unitId, $blockerHex, $target, 'slip');
    }

    private static function resolveFall(array $state, string $unitId, string $hex, int $levels, RandomSource $random, array &$events): array
    {
        for ($level = 0; $level < $levels && $state['phase'] === 'active' && $state['units'][$unitId]['hex'] !== null; $level++) {
            $roll = (new CustomDice())->roll(1, $random);
            $events[] = MatchEngine::event('terrainDamage', [$hex], [
                'unitId' => $unitId, 'terrain' => 'fall', 'cause' => 'slipFall', 'dice' => 1,
                'faces' => $roll['faces'], 'wounds' => $roll['swords'], 'hex' => $hex,
            ]);
            $state = Wounds::apply($state, $unitId, $roll['swords'], $events);
        }
        return $state;
    }

    private static function slideEvent(array &$events, string $unitId, string $from, string $to, array $path, array $direction, string $stopped): void
    {
        $events[] = MatchEngine::event('unitSlid', array_values(array_unique([$from, ...$path])), [
            'unitId' => $unitId, 'from' => $from, 'to' => $to, 'path' => $path,
            'direction' => array_values($direction), 'stopped' => $stopped,
        ]);
    }

    private static function forwardHex(string $hex, array $direction): string
    {
        [$q, $r] = Hex::parse($hex);
        return Hex::key($q + (int)$direction[0], $r + (int)$direction[1]);
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

    private static function invalidChoice(): never
    {
        throw new GameServiceException('invalidSlipChoice', 'That ice movement choice is no longer legal.');
    }
}
