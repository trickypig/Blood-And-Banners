<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Modes;

use BloodAndBanners\Game\GameServiceException;
use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Terrain;

/** The Void Lord places monsters from a tier-point budget inside spawn zones, then confirms. */
final class CoopSetup
{
    public const COSTS = ['common' => 1, 'elite' => 2, 'boss' => 4];
    public const KINDS = ['placeMonster', 'removeMonster', 'confirmSetup'];
    // Monster cards can share a name with a different hero ability (e.g. Fireball).
    // Enable only the monster rules verified for this slice, never every registry match.
    private const PLAYABLE_ESSENCES = [
        'Pack Tactics', 'Shark Instinct', 'Outrage', 'Frightening Presence',
        'Ogre Rivalry', 'Stoneskin', 'Hollow Bones', 'Flying',
    ];
    private const MESSAGES = [
        'unsupportedCommand' => 'This command is not legal in the current game phase.',
        'notVoidLord' => 'Only the Void Lord sets up the monsters.',
        'unknownMonster' => 'That monster cannot be summoned.',
        'outsideSpawnZone' => 'Monsters can only be placed in a Void spawn zone.',
        'hexOccupied' => 'Another monster already stands there.',
        'hexNotEnterable' => 'A monster cannot be placed on that hex.',
        'budgetExceeded' => 'That monster costs more than the remaining budget.',
        'unknownUnit' => 'That monster is not on the battlefield.',
        'setupEmpty' => 'Place at least one monster before confirming.',
    ];

    public function __construct(private readonly Catalog $catalog)
    {
    }

    public static function cost(array $monster): int
    {
        return self::COSTS[$monster['tier']] ?? self::COSTS['common'];
    }

    public static function isAbilityPlayable(array $ability): bool
    {
        return ($ability['type'] ?? '') === 'Essence'
            && in_array($ability['name'], self::PLAYABLE_ESSENCES, true)
            && AbilityRegistry::isPlayable($ability['name']);
    }

    public function legalActions(array $state, string $seatId): array
    {
        if ($seatId !== $state['voidLordSeatId']) {
            return [];
        }
        $actions = [];
        $remaining = $state['setup']['budget'] - $state['setup']['spent'];
        $free = self::freeSpawnHexes($state);
        $cheapest = min(array_map(self::cost(...), $this->catalog->monsters()) ?: [PHP_INT_MAX]);
        if ($free !== [] && $cheapest <= $remaining) {
            $actions[] = ['kind' => 'placeMonster', 'unitId' => null, 'targetHexes' => $free];
        }
        foreach ($state['units'] as $unitId => $unit) {
            $actions[] = ['kind' => 'removeMonster', 'unitId' => (string)$unitId, 'targetHexes' => [$unit['hex']]];
        }
        if ($state['units'] !== []) {
            $actions[] = ['kind' => 'confirmSetup', 'unitId' => null, 'targetHexes' => []];
        }
        return $actions;
    }

    public function apply(array $state, string $seatId, string $kind, array $payload): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            self::fail('unsupportedCommand');
        }
        if ($seatId !== $state['voidLordSeatId']) {
            self::fail('notVoidLord');
        }
        $events = [];
        if ($kind === 'placeMonster') {
            $monster = $this->catalog->monster(self::text($payload, 'monster'));
            if ($monster === null) {
                self::fail('unknownMonster');
            }
            $hex = self::text($payload, 'hex');
            if (!isset($state['board'][$hex]) || $state['board'][$hex]['spawnZone'] !== 'void') {
                self::fail('outsideSpawnZone');
            }
            if (self::occupied($state, $hex)) {
                self::fail('hexOccupied');
            }
            if ($state['board'][$hex]['sightBlocker'] || Terrain::type($state['board'][$hex]) !== null) {
                self::fail('hexNotEnterable');
            }
            $cost = self::cost($monster);
            if ($state['setup']['spent'] + $cost > $state['setup']['budget']) {
                self::fail('budgetExceeded');
            }
            $unitId = 'v-m' . $state['setup']['nextMonster'];
            $state['setup']['nextMonster']++;
            $state['setup']['spent'] += $cost;
            $state['units'][$unitId] = [
                'unitId' => $unitId,
                'seatId' => $state['voidLordSeatId'],
                'side' => 'void',
                'isHero' => false,
                'tier' => $monster['tier'],
                'biome' => $monster['biome'],
                'name' => $monster['name'],
                'image' => $monster['image'],
                'move' => $monster['move'],
                'range' => $monster['range'],
                'power' => $monster['power'],
                'defense' => $monster['defense'],
                'hp' => $monster['hp'],
                'abilities' => array_values(array_filter($monster['abilities'], self::isAbilityPlayable(...))),
                'inactiveAbilities' => array_values(array_filter($monster['abilities'], static fn(array $ability): bool => !self::isAbilityPlayable($ability))),
                'wounds' => 0,
                'hex' => $hex,
                'flags' => [],
            ];
            $events[] = MatchEngine::event('monsterPlaced', [$hex], ['unitId' => $unitId, 'monster' => $monster['name'], 'hex' => $hex, 'cost' => $cost]);
        } elseif ($kind === 'removeMonster') {
            $unitId = self::text($payload, 'unitId');
            $unit = $state['units'][$unitId] ?? null;
            if ($unit === null) {
                self::fail('unknownUnit');
            }
            $cost = self::cost($unit);
            $state['setup']['spent'] -= $cost;
            unset($state['units'][$unitId]);
            $events[] = MatchEngine::event('monsterRemoved', [$unit['hex']], ['unitId' => $unitId, 'monster' => $unit['name'], 'hex' => $unit['hex'], 'cost' => $cost]);
        } else {
            if ($state['units'] === []) {
                self::fail('setupEmpty');
            }
            $state['setup']['confirmed'] = true;
            $state['phase'] = 'draft';
            $state['draft']['pickingSeatId'] = $state['initiative']['order'][0];
            $state['draft']['pickIndex'] = 0;
            $events[] = MatchEngine::event('setupConfirmed', [], ['monsters' => count($state['units'])]);
        }
        return ['state' => $state, 'events' => $events];
    }

    private static function freeSpawnHexes(array $state): array
    {
        $free = [];
        foreach ($state['board'] as $key => $hex) {
            $key = (string)$key;
            if ($hex['spawnZone'] === 'void' && !$hex['sightBlocker'] && Terrain::type($hex) === null && !self::occupied($state, $key)) {
                $free[] = $key;
            }
        }
        sort($free, SORT_STRING);
        return $free;
    }

    private static function occupied(array $state, string $hex): bool
    {
        foreach ($state['units'] as $unit) {
            if ($unit['hex'] === $hex) {
                return true;
            }
        }
        return false;
    }

    private static function text(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    private static function fail(string $code): never
    {
        throw new GameServiceException($code, self::MESSAGES[$code]);
    }
}
