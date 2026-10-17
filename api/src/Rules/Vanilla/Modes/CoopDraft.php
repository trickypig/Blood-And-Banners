<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Modes;

use BloodAndBanners\Game\GameServiceException;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Placement;
use BloodAndBanners\Rules\Vanilla\SnakeDraftOrder;
use BloodAndBanners\Rules\Vanilla\Turn;

/** Hero players snake-draft two heroes and one army each, then deploy around the shared start. */
final class CoopDraft
{
    private const KINDS = ['pickHero', 'pickArmy'];
    private const MESSAGES = [
        'unsupportedCommand' => 'This command is not legal in the current game phase.',
        'setupConfirmed' => 'The Void Lord has already confirmed the monsters.',
        'notYourPick' => 'It is not your pick.',
        'decisionAlreadyMade' => 'That decision has already been made.',
        'heroesComplete' => 'You already lead two heroes.',
        'unknownHero' => 'That hero is not in the catalog.',
        'unknownArmy' => 'That army is not in the catalog.',
        'heroTaken' => 'Another commander has already claimed that hero.',
        'armyTaken' => 'Another commander has already claimed that army.',
    ];

    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function legalActions(array $state, string $seatId): array
    {
        if ($state['draft']['pickingSeatId'] !== $seatId) {
            return [];
        }
        return array_map(
            static fn(string $kind): array => ['kind' => $kind, 'unitId' => null, 'targetHexes' => []],
            self::openDecisions($state, $seatId)
        );
    }

    public function apply(array $state, string $seatId, string $kind, array $payload): array
    {
        if (in_array($kind, CoopSetup::KINDS, true)) {
            self::fail('setupConfirmed');
        }
        if (!in_array($kind, self::KINDS, true)) {
            self::fail('unsupportedCommand');
        }
        if ($state['draft']['pickingSeatId'] !== $seatId) {
            self::fail('notYourPick');
        }
        $draft = $state['draft'];
        if ($kind === 'pickHero') {
            if (count($draft['heroesBySeat'][$seatId]) >= 2) {
                self::fail('heroesComplete');
            }
            $value = self::text($payload, 'hero');
            if ($this->catalog->hero($value) === null) {
                self::fail('unknownHero');
            }
            foreach ($draft['heroesBySeat'] as $heroes) {
                if (in_array($value, $heroes, true)) {
                    self::fail('heroTaken');
                }
            }
            $draft['heroesBySeat'][$seatId][] = $value;
        } else {
            if ($draft['armyBySeat'][$seatId] !== null) {
                self::fail('decisionAlreadyMade');
            }
            $value = self::text($payload, 'army');
            if ($this->catalog->army($value) === null) {
                self::fail('unknownArmy');
            }
            if (in_array($value, $draft['armyBySeat'], true)) {
                self::fail('armyTaken');
            }
            $draft['armyBySeat'][$seatId] = $value;
        }
        $state['draft'] = $draft;
        $events = [MatchEngine::event('draftPicked', [], ['seatId' => $seatId, 'decision' => $kind, 'value' => $value])];

        $order = $state['initiative']['order'];
        $pickIndex = $draft['pickIndex'] + 1;
        $nextSeat = null;
        for ($offset = 0; $offset < count($order) * 2; $offset++) {
            $candidate = SnakeDraftOrder::seatAt($order, $pickIndex + $offset);
            if (self::openDecisions($state, $candidate) !== []) {
                $pickIndex += $offset;
                $nextSeat = $candidate;
                break;
            }
        }
        if ($nextSeat !== null) {
            $state['draft']['pickIndex'] = $pickIndex;
            $state['draft']['pickingSeatId'] = $nextSeat;
            return ['state' => $state, 'events' => $events];
        }
        $state['draft']['pickingSeatId'] = null;
        if (!$state['setup']['confirmed']) {
            // The heroes are ready first; the Void Lord's confirm starts the battle.
            return ['state' => $state, 'events' => $events];
        }
        [$state, $placed] = $this->deploy($state);
        $events[] = MatchEngine::event('armiesPlaced', array_values($placed), ['units' => $placed]);
        return ['state' => $state, 'events' => $events];
    }

    /** True once every hero seat holds two heroes and an army. */
    public static function complete(array $state): bool
    {
        foreach ($state['initiative']['order'] as $seatId) {
            if (self::openDecisions($state, $seatId) !== []) {
                return false;
            }
        }
        return true;
    }

    private static function openDecisions(array $state, string $seatId): array
    {
        $open = [];
        if (count($state['draft']['heroesBySeat'][$seatId]) < 2) {
            $open[] = 'pickHero';
        }
        if ($state['draft']['armyBySeat'][$seatId] === null) {
            $open[] = 'pickArmy';
        }
        return $open;
    }

    /**
     * Places every hero seat around the shared start and opens round one.
     * @return array{0: array, 1: array<string,string>}
     */
    public function deploy(array $state): array
    {
        $layout = CoopMode::layout($state['board']);
        $order = $state['initiative']['order'];
        $rosters = [];
        foreach ($order as $seatId) {
            $prefix = 's' . (array_search($seatId, $state['seats'], true) + 1);
            $roster = [];
            foreach ($state['draft']['heroesBySeat'][$seatId] as $index => $hero) {
                $roster[$prefix . '-hero' . ($index + 1)] = ['isHero' => true] + $this->catalog->hero($hero);
            }
            foreach ($this->catalog->army($state['draft']['armyBySeat'][$seatId])['units'] as $index => $stats) {
                $roster[$prefix . '-u' . ($index + 1)] = ['isHero' => false] + $stats;
            }
            $rosters[$seatId] = $roster;
        }
        $interleaved = [];
        for ($round = 0; $round < CoopMode::UNITS_PER_HERO_PLAYER; $round++) {
            foreach ($order as $seatId) {
                $ids = array_keys($rosters[$seatId]);
                if (isset($ids[$round])) {
                    $interleaved[] = $ids[$round];
                }
            }
        }
        $occupied = array_fill_keys($layout['spawn'], true);
        foreach ($state['units'] as $unit) {
            $occupied[$unit['hex']] = true;
        }
        $positions = Placement::place($state['board'], $layout['start'], $layout['facing'], $interleaved, $occupied);
        $placed = [];
        foreach ($rosters as $seatId => $roster) {
            foreach ($roster as $unitId => $stats) {
                $state['units'][$unitId] = ['unitId' => $unitId, 'seatId' => $seatId, 'side' => 'heroes'] + $stats
                    + ['wounds' => 0, 'hex' => $positions[$unitId], 'flags' => []];
                $placed[$unitId] = $positions[$unitId];
            }
        }
        ksort($state['units'], SORT_STRING);
        ksort($placed, SORT_STRING);
        $state['phase'] = 'active';
        $state['turn'] = Turn::fresh($state, $order[0], 1, 0);
        return [$state, $placed];
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
