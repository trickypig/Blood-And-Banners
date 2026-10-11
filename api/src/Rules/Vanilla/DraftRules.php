<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Game\GameServiceException;

final class DraftRules
{
    private const KINDS = ['chooseFirstPlayer', 'chooseStartPosition', 'pickHero', 'pickArmy'];
    private const MESSAGES = [
        'unsupportedCommand' => 'This command is not legal in the current game phase.',
        'notYourPick' => 'It is not your pick.',
        'decisionAlreadyMade' => 'That decision has already been made.',
        'invalidSeat' => 'Choose one of the two commanders.',
        'invalidStart' => 'Choose start position 1 or 2.',
        'unknownHero' => 'That hero is not in the catalog.',
        'unknownArmy' => 'That army is not in the catalog.',
        'heroTaken' => 'Another commander has already claimed that hero.',
        'armyTaken' => 'Another commander has already claimed that army.',
    ];

    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function openDecisions(array $state, string $seatId): array
    {
        $draft = $state['draft'];
        $open = [];
        if ($draft['firstSeatId'] === null) {
            $open[] = 'chooseFirstPlayer';
        }
        if ($draft['startBySeat'][$seatId] === null) {
            $open[] = 'chooseStartPosition';
        }
        if ($draft['heroBySeat'][$seatId] === null) {
            $open[] = 'pickHero';
        }
        if ($draft['armyBySeat'][$seatId] === null) {
            $open[] = 'pickArmy';
        }
        return $open;
    }

    public function legalActions(array $state, string $seatId): array
    {
        if ($state['draft']['pickingSeatId'] !== $seatId) {
            return [];
        }
        return array_map(
            static fn(string $kind): array => ['kind' => $kind, 'unitId' => null, 'targetHexes' => []],
            $this->openDecisions($state, $seatId)
        );
    }

    public function apply(array $state, string $seatId, string $kind, array $payload): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            self::fail('unsupportedCommand');
        }
        if ($state['draft']['pickingSeatId'] !== $seatId) {
            self::fail('notYourPick');
        }
        if (!in_array($kind, $this->openDecisions($state, $seatId), true)) {
            self::fail('decisionAlreadyMade');
        }
        $other = MatchEngine::otherSeat($state, $seatId);
        $draft = $state['draft'];
        if ($kind === 'chooseFirstPlayer') {
            $value = (string)($payload['seatId'] ?? '');
            if (!in_array($value, $state['seats'], true)) {
                self::fail('invalidSeat');
            }
            $draft['firstSeatId'] = $value;
        } elseif ($kind === 'chooseStartPosition') {
            $value = $payload['start'] ?? null;
            if ($value !== 1 && $value !== 2) {
                self::fail('invalidStart');
            }
            $draft['startBySeat'][$seatId] = $value;
            $draft['startBySeat'][$other] = 3 - $value;
        } elseif ($kind === 'pickHero') {
            $value = (string)($payload['hero'] ?? '');
            if ($this->catalog->hero($value) === null) {
                self::fail('unknownHero');
            }
            if (in_array($value, $draft['heroBySeat'], true)) {
                self::fail('heroTaken');
            }
            $draft['heroBySeat'][$seatId] = $value;
        } else {
            $value = (string)($payload['army'] ?? '');
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

        if ($this->openDecisions($state, $other) !== []) {
            $state['draft']['pickingSeatId'] = $other;
        } elseif ($this->openDecisions($state, $seatId) !== []) {
            $state['draft']['pickingSeatId'] = $seatId;
        } else {
            $state['draft']['pickingSeatId'] = null;
            [$state, $placed] = $this->deploy($state);
            $events[] = MatchEngine::event('armiesPlaced', array_values($placed), ['units' => $placed]);
        }
        return ['state' => $state, 'events' => $events];
    }

    private function deploy(array $state): array
    {
        $homes = Board::homes($state['board']);
        $seatsByStart = [];
        foreach ($state['seats'] as $seatId) {
            $seatsByStart[$state['draft']['startBySeat'][$seatId]] = $seatId;
        }
        ksort($seatsByStart);
        $units = [];
        $placed = [];
        $occupied = [];
        foreach ($seatsByStart as $start => $seatId) {
            $prefix = 's' . (array_search($seatId, $state['seats'], true) + 1);
            $roster = [$prefix . '-hero' => ['isHero' => true] + $this->catalog->hero($state['draft']['heroBySeat'][$seatId])];
            foreach ($this->catalog->army($state['draft']['armyBySeat'][$seatId])['units'] as $index => $stats) {
                $roster[$prefix . '-u' . ($index + 1)] = ['isHero' => false] + $stats;
            }
            $positions = Placement::place($state['board'], $homes[$start], $homes[3 - $start], array_keys($roster), $occupied);
            foreach ($roster as $unitId => $stats) {
                $units[$unitId] = ['unitId' => $unitId, 'seatId' => $seatId] + $stats + ['wounds' => 0, 'hex' => $positions[$unitId]];
                $occupied[$positions[$unitId]] = true;
                $placed[$unitId] = $positions[$unitId];
            }
        }
        ksort($units, SORT_STRING);
        ksort($placed, SORT_STRING);
        $state['units'] = $units;
        $state['phase'] = 'active';
        $state['turn'] = Turn::fresh($state, $state['draft']['firstSeatId'], 1);
        return [$state, $placed];
    }

    private static function fail(string $code): never
    {
        throw new GameServiceException($code, self::MESSAGES[$code]);
    }
}
