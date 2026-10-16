<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\Vanilla\Conditions\Condition;
use BloodAndBanners\Rules\Vanilla\Conditions\ConditionRegistry;

/**
 * Conditions on units. Expiry kinds: afterTurn {turnNumber} (ends when that turn ends), turnStart/turnEnd {seatId}
 * (as for effects), whileOnTerrain and untilCleared (reserved for terrain; never expired by turn boundaries).
 */
final class UnitConditions
{
    public static function afterTurn(int $turnNumber): array
    {
        return ['kind' => 'afterTurn', 'turnNumber' => $turnNumber];
    }

    public static function apply(array $state, string $name, string $unitId, ?string $sourceUnitId, array $expires, array &$events, array $data = []): array
    {
        $state['conditions'] = array_values(array_filter(
            $state['conditions'],
            static fn(array $condition): bool => !($condition['name'] === $name && $condition['unitId'] === $unitId)
        ));
        $state['conditions'][] = ['name' => $name, 'unitId' => $unitId, 'sourceUnitId' => $sourceUnitId, 'expires' => $expires, 'data' => $data];
        $hex = $state['units'][$unitId]['hex'];
        $events[] = MatchEngine::event('conditionApplied', $hex === null ? [] : [$hex], [
            'name' => $name, 'unitId' => $unitId, 'sourceUnitId' => $sourceUnitId, 'expires' => $expires,
            ...($data === [] ? [] : ['data' => $data]),
        ]);
        return $state;
    }

    public static function forUnit(array $state, string $unitId): array
    {
        return array_values(array_filter(
            $state['conditions'] ?? [],
            static fn(array $condition): bool => $condition['unitId'] === $unitId
        ));
    }

    public static function has(array $state, string $unitId, string $name): bool
    {
        return in_array($name, array_column(self::forUnit($state, $unitId), 'name'), true);
    }

    public static function named(array $state, string $unitId, string $name): ?array
    {
        foreach (self::forUnit($state, $unitId) as $condition) {
            if ($condition['name'] === $name) {
                return $condition;
            }
        }
        return null;
    }

    public static function updateData(array $state, string $unitId, string $name, array $data): array
    {
        foreach ($state['conditions'] as $index => $condition) {
            if ($condition['unitId'] === $unitId && $condition['name'] === $name) {
                $state['conditions'][$index]['data'] = $data;
                break;
            }
        }
        return $state;
    }

    public static function clear(array $state, string $unitId, string $name, array &$events): array
    {
        $before = count($state['conditions'] ?? []);
        $state['conditions'] = array_values(array_filter(
            $state['conditions'] ?? [],
            static fn(array $condition): bool => !($condition['unitId'] === $unitId && $condition['name'] === $name)
        ));
        if (count($state['conditions']) !== $before) {
            $events[] = MatchEngine::event('conditionExpired', [], ['name' => $name, 'unitId' => $unitId]);
        }
        return $state;
    }

    public static function any(array $state, string $unitId, callable $test): bool
    {
        foreach (self::forUnit($state, $unitId) as $condition) {
            $handler = ConditionRegistry::get($condition['name']);
            if ($handler !== null && $test($handler)) {
                return true;
            }
        }
        return false;
    }

    public static function statDelta(array $state, string $unitId, string $stat, array $context): int
    {
        $delta = 0;
        foreach (self::forUnit($state, $unitId) as $condition) {
            $delta += ConditionRegistry::get($condition['name'])?->statDelta($state, $unitId, $stat, $context) ?? 0;
        }
        return $delta;
    }

    /** Silently drop a unit's conditions (it left the battle). */
    public static function clearUnit(array $state, string $unitId): array
    {
        $state['conditions'] = array_values(array_filter(
            $state['conditions'] ?? [],
            static fn(array $condition): bool => $condition['unitId'] !== $unitId
        ));
        return $state;
    }

    /** Run turn-end hooks, then expire conditions bound to the ending turn. Call before the turn record changes. */
    public static function atTurnEnd(array $state, array &$events): array
    {
        $state = self::runHooks($state, 'onTurnEnd', $events);
        $number = $state['turn']['number'];
        $seat = $state['turn']['activeSeatId'];
        return self::expireWhere($state, static fn(array $expires): bool =>
            ($expires['kind'] === 'afterTurn' && $expires['turnNumber'] === $number)
            || ($expires['kind'] === 'turnEnd' && $expires['seatId'] === $seat && $number > ($expires['afterTurnNumber'] ?? -1)), $events);
    }

    /** Run turn-start hooks, then expire conditions bound to the starting seat. Call after the new turn is set. */
    public static function atTurnStart(array $state, array &$events): array
    {
        $seat = $state['turn']['activeSeatId'];
        $state = self::expireWhere($state, static fn(array $expires): bool =>
            $expires['kind'] === 'turnStart' && $expires['seatId'] === $seat, $events);
        return self::runHooks($state, 'onTurnStart', $events);
    }

    private static function runHooks(array $state, string $hook, array &$events): array
    {
        foreach ($state['conditions'] as $condition) {
            $handler = ConditionRegistry::get($condition['name']);
            if ($handler instanceof Condition && $state['units'][$condition['unitId']]['hex'] !== null) {
                $state = $handler->{$hook}($state, $condition['unitId'], $events);
            }
        }
        return $state;
    }

    private static function expireWhere(array $state, callable $matches, array &$events): array
    {
        $kept = [];
        foreach ($state['conditions'] as $condition) {
            if ($matches($condition['expires'])) {
                $events[] = MatchEngine::event('conditionExpired', [], ['name' => $condition['name'], 'unitId' => $condition['unitId']]);
                continue;
            }
            $kept[] = $condition;
        }
        $state['conditions'] = $kept;
        return $state;
    }
}
