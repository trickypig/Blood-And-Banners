<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Game\GameServiceException;
use BloodAndBanners\Rules\RandomSource;

final class BattleRules
{
    private const KINDS = ['moveUnit', 'attackUnit', 'endActivation', 'endTurn'];
    private const MESSAGES = [
        'unsupportedCommand' => 'This command is not legal in the current game phase.',
        'notYourTurn' => 'It is not your turn.',
        'unknownUnit' => 'That unit is not on the battlefield.',
        'notYourUnit' => 'You can only command your own units.',
        'unitAlreadyActivated' => 'That unit has already been activated this turn.',
        'noActivationsRemaining' => 'You have no activations left this turn.',
        'heroActivationRequired' => 'While your hero lives, only three other units may activate each turn.',
        'alreadyMoved' => 'That unit has already moved this activation.',
        'unitNotActive' => 'That unit is not the active unit.',
        'hexUnreachable' => 'That unit cannot reach that hex.',
        'targetOutOfRange' => 'That target is out of range.',
        'noLineOfSight' => 'That target is not in line of sight.',
    ];

    public function __construct(private readonly int $now)
    {
    }

    public function apply(array $state, string $seatId, string $kind, array $payload, RandomSource $random): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            self::fail('unsupportedCommand');
        }
        if ($state['turn']['activeSeatId'] !== $seatId) {
            self::fail('notYourTurn');
        }
        if ($kind === 'endTurn') {
            [$state, $events] = self::passTurn($state);
            return ['state' => $state, 'events' => $events];
        }

        $events = [];
        if ($kind === 'moveUnit') {
            $state = $this->move($state, $seatId, $payload, $random, $events);
        } elseif ($kind === 'attackUnit') {
            $state = $this->attack($state, $seatId, $payload, $random, $events);
        } else {
            $unitId = self::text($payload, 'unitId');
            if ($state['turn']['activeUnitId'] === null || $state['turn']['activeUnitId'] !== $unitId) {
                self::fail('unitNotActive');
            }
            $state['turn']['activeUnitId'] = null;
        }

        if ($state['phase'] === 'active' && !$this->canAct($state, $seatId)) {
            [$state, $passed] = self::passTurn($state);
            $events = array_merge($events, $passed);
        }
        return ['state' => $state, 'events' => $events];
    }

    public function legalActions(array $state, string $seatId): array
    {
        if ($state['phase'] !== 'active' || $state['turn']['activeSeatId'] !== $seatId) {
            return [];
        }
        $actions = [];
        foreach ($state['units'] as $unitId => $unit) {
            $unitId = (string)$unitId;
            if ($unit['seatId'] !== $seatId || $unit['hex'] === null) {
                continue;
            }
            $active = $state['turn']['activeUnitId'] === $unitId;
            if (!$active && $this->activationError($state, $unitId) !== null) {
                continue;
            }
            if (!$active || !$state['turn']['moved']) {
                $hexes = Movement::reachable($state, $unitId);
                if ($hexes !== []) {
                    $actions[] = ['kind' => 'moveUnit', 'unitId' => $unitId, 'targetHexes' => $hexes];
                }
            }
            $targets = Combat::targets($state, $unitId);
            if ($targets !== []) {
                $actions[] = [
                    'kind' => 'attackUnit',
                    'unitId' => $unitId,
                    'targetHexes' => array_map(static fn(string $id): string => $state['units'][$id]['hex'], $targets),
                ];
            }
        }
        if ($state['turn']['activeUnitId'] !== null) {
            $actions[] = ['kind' => 'endActivation', 'unitId' => $state['turn']['activeUnitId'], 'targetHexes' => []];
        }
        $actions[] = ['kind' => 'endTurn', 'unitId' => null, 'targetHexes' => []];
        return $actions;
    }

    private function move(array $state, string $seatId, array $payload, RandomSource $random, array &$events): array
    {
        $unitId = self::text($payload, 'unitId');
        $to = self::text($payload, 'to');
        $state = $this->activate($state, $seatId, $unitId);
        if ($state['turn']['moved']) {
            self::fail('alreadyMoved');
        }
        if (!in_array($to, Movement::reachable($state, $unitId), true)) {
            self::fail('hexUnreachable');
        }
        $from = $state['units'][$unitId]['hex'];
        $hits = [];
        foreach (Movement::disengagedFrom($state, $unitId, $to) as $enemyId) {
            $hits[] = ['byUnitId' => $enemyId] + Combat::disengagementHit($random);
        }
        $state['units'][$unitId]['hex'] = $to;
        $state['turn']['moved'] = true;
        $events[] = MatchEngine::event('unitMoved', [$from, $to], [
            'unitId' => $unitId,
            'from' => $from,
            'to' => $to,
            'disengagement' => $hits,
        ]);
        return Wounds::apply($state, $unitId, array_sum(array_column($hits, 'wounds')), $events);
    }

    private function attack(array $state, string $seatId, array $payload, RandomSource $random, array &$events): array
    {
        $unitId = self::text($payload, 'unitId');
        $targetId = self::text($payload, 'targetUnitId');
        $state = $this->activate($state, $seatId, $unitId);
        $target = $state['units'][$targetId] ?? null;
        if ($target === null || $target['hex'] === null || $target['seatId'] === $seatId) {
            self::fail('unknownUnit');
        }
        $blocked = Combat::targetError($state, $unitId, $targetId);
        if ($blocked !== null) {
            self::fail($blocked);
        }
        $state['turn']['attacks']++;
        $state['turn']['activeUnitId'] = null;
        return (new StepRunner($random, $this->now))->run($state, [StepRunner::strike($unitId, $targetId)], $events);
    }

    private function activate(array $state, string $seatId, string $unitId): array
    {
        $unit = $state['units'][$unitId] ?? null;
        if ($unit === null || $unit['hex'] === null) {
            self::fail('unknownUnit');
        }
        if ($unit['seatId'] !== $seatId) {
            self::fail('notYourUnit');
        }
        if ($state['turn']['activeUnitId'] === $unitId) {
            return $state;
        }
        $blocked = $this->activationError($state, $unitId);
        if ($blocked !== null) {
            self::fail($blocked);
        }
        $state['turn']['activeUnitId'] = $unitId;
        $state['turn']['activated'][] = $unitId;
        $state['turn']['moved'] = false;
        return $state;
    }

    private function activationError(array $state, string $unitId): ?string
    {
        $activated = $state['turn']['activated'];
        if (in_array($unitId, $activated, true)) {
            return 'unitAlreadyActivated';
        }
        if (Turn::activationsRemaining($state) <= 0) {
            return 'noActivationsRemaining';
        }
        $unit = $state['units'][$unitId];
        if (!$unit['isHero'] && self::heroAlive($state, $unit['seatId'])) {
            $others = count(array_filter($activated, static fn(string $id): bool => !$state['units'][$id]['isHero']));
            if ($others >= 3) {
                return 'heroActivationRequired';
            }
        }
        return null;
    }

    private function canAct(array $state, string $seatId): bool
    {
        foreach ($this->legalActions($state, $seatId) as $action) {
            if ($action['kind'] === 'moveUnit' || $action['kind'] === 'attackUnit') {
                return true;
            }
        }
        return false;
    }

    private static function passTurn(array $state): array
    {
        $current = $state['turn']['activeSeatId'];
        $next = MatchEngine::otherSeat($state, $current);
        $events = [];
        $state = Effects::expire($state, $current, Effects::UNTIL_END_OF_TURN, $events);
        $round = $state['turn']['round'] + ($next === $state['draft']['firstSeatId'] ? 1 : 0);
        $state['turn'] = Turn::fresh($state, $next, $round);
        $events[] = MatchEngine::event('turnEnded', [], ['seatId' => $current, 'nextSeatId' => $next, 'round' => $round]);
        $state = Effects::expire($state, $next, Effects::UNTIL_NEXT_TURN, $events);
        return [$state, $events];
    }

    private static function heroAlive(array $state, string $seatId): bool
    {
        foreach ($state['units'] as $unit) {
            if ($unit['seatId'] === $seatId && $unit['isHero'] && $unit['hex'] !== null) {
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
