<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Game\GameServiceException;
use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\Abilities\Ability;
use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;
use BloodAndBanners\Rules\Vanilla\Abilities\SpecialAttack;
use BloodAndBanners\Rules\Vanilla\Abilities\TargetedAbility;
use BloodAndBanners\Rules\Vanilla\Conditions\Condition;

final class BattleRules
{
    public const CHOICE_TIMEOUT_SECONDS = 45;

    private const KINDS = ['moveUnit', 'attackUnit', 'specialAttack', 'useAbility', 'resolveChoice', 'endActivation', 'endTurn'];
    private const MESSAGES = [
        'unitStunned' => 'A stunned unit cannot move.',
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
        'unknownAbility' => 'That unit does not have that ability.',
        'abilityNotPlayable' => 'That ability is not yet in the digital game.',
        'abilityLocked' => 'Fragile Focus: that unit cannot cast spells this turn.',
        'invalidAbilityTarget' => 'That is not a valid target for this ability.',
        'chainTooLong' => 'Chain Lightning can only travel 5 hexes in total.',
        'gustNeedsStillness' => 'Gust of Wind must be used instead of moving.',
        'invalidDestination' => 'That unit cannot be teleported there.',
        'abilityAlreadyUsed' => 'That ability has already been used this turn.',
        'choicePending' => 'Waiting for a decision before the battle can continue.',
        'notYourChoice' => 'That decision belongs to the other commander.',
        'unknownChoice' => 'That decision is no longer open.',
        'choiceNotExpired' => 'Your opponent still has time to decide.',
        'noAttacksLeft' => 'That unit has already made all of its attacks this activation.',
    ];

    public function __construct(private readonly int $now)
    {
    }

    public function apply(array $state, string $seatId, string $kind, array $payload, RandomSource $random): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            self::fail('unsupportedCommand');
        }
        if ($state['pending'] !== null || $kind === 'resolveChoice') {
            return $this->resolveChoice($state, $seatId, $kind, $payload, $random);
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
        } elseif ($kind === 'specialAttack') {
            $state = $this->specialAttack($state, $seatId, $payload, $random, $events);
        } elseif ($kind === 'useAbility') {
            $state = $this->useAbility($state, $seatId, $payload, $random, $events);
        } else {
            $unitId = self::text($payload, 'unitId');
            if ($state['turn']['activeUnitId'] === null || $state['turn']['activeUnitId'] !== $unitId) {
                self::fail('unitNotActive');
            }
            $state['turn']['activeUnitId'] = null;
        }

        if ($state['phase'] === 'active' && $state['pending'] === null && !$this->canAct($state, $seatId)) {
            [$state, $passed] = self::passTurn($state);
            $events = array_merge($events, $passed);
        }
        return ['state' => $state, 'events' => $events];
    }

    public function legalActions(array $state, string $seatId): array
    {
        if ($state['phase'] !== 'active') {
            return [];
        }
        if ($state['pending'] !== null) {
            $choice = $state['pending']['choice'];
            $mayKeep = $this->now - $choice['openedAt'] >= self::CHOICE_TIMEOUT_SECONDS;
            return $seatId === $choice['seatId'] || $mayKeep
                ? [['kind' => 'resolveChoice', 'unitId' => $choice['unitId'], 'targetHexes' => []]]
                : [];
        }
        if ($state['turn']['activeSeatId'] !== $seatId) {
            return [];
        }
        $actions = [];
        foreach ($state['units'] as $unitId => $unit) {
            $unitId = (string)$unitId;
            if ($unit['seatId'] !== $seatId || $unit['hex'] === null) {
                continue;
            }
            $active = $state['turn']['activeUnitId'] === $unitId;
            $activated = in_array($unitId, $state['turn']['activated'], true);
            $canStart = !$activated && $this->activationError($state, $unitId) === null;
            if ($activated || $canStart) {
                foreach (AbilityRegistry::abilitiesOf($unit) as $ability) {
                    if (!$ability instanceof TargetedAbility || in_array($ability->name, $state['turn']['abilitiesUsed'][$unitId] ?? [], true) || $ability->unavailable($state, $unitId) !== null) {
                        continue;
                    }
                    $targets = $ability->targets($state, $unitId);
                    if ($targets !== []) {
                        $actions[] = [
                            'kind' => 'useAbility',
                            'unitId' => $unitId,
                            'ability' => $ability->name,
                            'targetHexes' => array_map(static fn(string $id): string => $state['units'][$id]['hex'], $targets),
                        ];
                    }
                }
            }
            if (!$active && !$canStart) {
                continue;
            }
            if (self::canMove($state, $unitId)) {
                $budget = Stats::effective($state, $unitId, 'move') - ($active ? $state['turn']['moveSpent'] : 0);
                $hexes = Movement::reachable($state, $unitId, $budget);
                if ($hexes !== []) {
                    $actions[] = ['kind' => 'moveUnit', 'unitId' => $unitId, 'targetHexes' => $hexes];
                }
            }
            $targets = self::attacksLeft($state, $unitId) ? Combat::targets($state, $unitId) : [];
            if ($targets !== []) {
                $actions[] = [
                    'kind' => 'attackUnit',
                    'unitId' => $unitId,
                    'targetHexes' => array_map(static fn(string $id): string => $state['units'][$id]['hex'], $targets),
                ];
            }
            if (self::attacksLeft($state, $unitId) && !self::spellsLocked($state, $unitId)) {
                foreach (AbilityRegistry::abilitiesOf($unit) as $ability) {
                    if (!$ability instanceof SpecialAttack) {
                        continue;
                    }
                    $targets = $ability->targets($state, $unitId);
                    if ($targets !== []) {
                        $actions[] = [
                            'kind' => 'specialAttack',
                            'unitId' => $unitId,
                            'ability' => $ability->name,
                            'targetHexes' => array_map(static fn(string $id): string => $state['units'][$id]['hex'], $targets),
                        ];
                    }
                }
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
        if (self::rooted($state, $unitId)) {
            self::fail('unitStunned');
        }
        if (!self::canMove($state, $unitId)) {
            self::fail('alreadyMoved');
        }
        $budget = Stats::effective($state, $unitId, 'move') - $state['turn']['moveSpent'];
        $costs = Movement::costs($state, $unitId, $budget);
        if (!isset($costs[$to])) {
            self::fail('hexUnreachable');
        }
        $from = $state['units'][$unitId]['hex'];
        $hits = [];
        $evades = AbilityRegistry::any($state['units'][$unitId], static fn(Ability $ability): bool => $ability->evades());
        foreach (Movement::disengagedFrom($state, $unitId, $to) as $enemyId) {
            if ($evades) {
                $roll = $random->roll(20);
                if ($roll > 10) {
                    $hits[] = ['byUnitId' => $enemyId, 'faces' => [], 'wounds' => 0, 'evasionRoll' => $roll, 'evaded' => true];
                    continue;
                }
                $hits[] = ['byUnitId' => $enemyId] + Combat::disengagementHit($random) + ['evasionRoll' => $roll, 'evaded' => false];
                continue;
            }
            $hits[] = ['byUnitId' => $enemyId] + Combat::disengagementHit($random);
        }
        $state['units'][$unitId]['hex'] = $to;
        $state['turn']['moveSpent'] += $costs[$to];
        if ($state['turn']['attacks'] > 0) {
            $state['turn']['movedAfterAttack'] = true;
        } else {
            $state['turn']['moved'] = true;
        }
        $events[] = MatchEngine::event('unitMoved', [$from, $to], [
            'unitId' => $unitId,
            'from' => $from,
            'to' => $to,
            'disengagement' => $hits,
        ]);
        $state = Wounds::apply($state, $unitId, array_sum(array_column($hits, 'wounds')), $events);
        return self::closeIfSpent($state, $unitId);
    }

    private function attack(array $state, string $seatId, array $payload, RandomSource $random, array &$events): array
    {
        $unitId = self::text($payload, 'unitId');
        $targetId = self::text($payload, 'targetUnitId');
        $state = $this->activate($state, $seatId, $unitId);
        if (!self::attacksLeft($state, $unitId)) {
            self::fail('noAttacksLeft');
        }
        $target = $state['units'][$targetId] ?? null;
        if ($target === null || $target['hex'] === null || $target['seatId'] === $seatId) {
            self::fail('unknownUnit');
        }
        $blocked = Combat::targetError($state, $unitId, $targetId);
        if ($blocked !== null) {
            self::fail($blocked);
        }
        $state['turn']['attacks']++;
        $state = self::closeIfSpent($state, $unitId);
        return (new StepRunner($random, $this->now))->run($state, [StepRunner::strike($unitId, $targetId)], $events);
    }

    private static function attackAllowance(array $unit): int
    {
        $allowance = 1;
        foreach (AbilityRegistry::abilitiesOf($unit) as $ability) {
            $allowance = max($allowance, $ability->attacksPerActivation());
        }
        return $allowance;
    }

    /** Whether $unitId may still attack, as the active unit or as a fresh activation. */
    private static function attacksLeft(array $state, string $unitId): bool
    {
        if ($state['turn']['activeUnitId'] !== $unitId) {
            return true;
        }
        return $state['turn']['attacks'] < self::attackAllowance($state['units'][$unitId]);
    }

    private static function stings(array $unit): bool
    {
        return AbilityRegistry::any($unit, static fn(Ability $ability): bool => $ability->movesAfterAttack());
    }

    private static function rooted(array $state, string $unitId): bool
    {
        return UnitConditions::any($state, $unitId, static fn(Condition $condition): bool => $condition->blocksMovement());
    }

    private static function canMove(array $state, string $unitId): bool
    {
        if (self::rooted($state, $unitId)) {
            return false;
        }
        $turn = $state['turn'];
        if ($turn['activeUnitId'] !== $unitId) {
            return true;
        }
        if ($turn['attacks'] === 0) {
            return !$turn['moved'];
        }
        return self::stings($state['units'][$unitId])
            && !$turn['movedAfterAttack']
            && $turn['moveSpent'] < Stats::effective($state, $unitId, 'move');
    }

    /** End the activation once the unit has nothing left to do with it. */
    private static function closeIfSpent(array $state, string $unitId): array
    {
        if ($state['turn']['activeUnitId'] !== $unitId) {
            return $state;
        }
        if (!self::attacksLeft($state, $unitId) && !($state['turn']['attacks'] > 0 && self::canMove($state, $unitId))) {
            $state['turn']['activeUnitId'] = null;
        }
        return $state;
    }

    private function specialAttack(array $state, string $seatId, array $payload, RandomSource $random, array &$events): array
    {
        $unitId = self::text($payload, 'unitId');
        $name = self::text($payload, 'ability');
        $targets = array_values(array_filter($payload['targetUnitIds'] ?? [], 'is_string'));
        $state = $this->activate($state, $seatId, $unitId);
        $ability = self::resolveAbility($state['units'][$unitId], $name, SpecialAttack::class);
        if (self::spellsLocked($state, $unitId)) {
            self::fail('abilityLocked');
        }
        if (!self::attacksLeft($state, $unitId)) {
            self::fail('noAttacksLeft');
        }
        $options = ['toHex' => self::text($payload, 'toHex')];
        $error = $ability->validate($state, $unitId, $targets, $options);
        if ($error !== null) {
            self::fail($error);
        }
        $state['turn']['attacks']++;
        $state = self::closeIfSpent($state, $unitId);
        return (new StepRunner($random, $this->now))->run($state, $ability->steps($state, $unitId, $targets, $options), $events);
    }

    /** The unit's handler for $name, which must be an instance of $class. */
    private static function resolveAbility(array $unit, string $name, string $class): Ability
    {
        $printed = in_array($name, array_column($unit['abilities'], 'name'), true);
        $handler = AbilityRegistry::find($unit, $name);
        if (!$printed) {
            self::fail('unknownAbility');
        }
        if ($handler === null) {
            self::fail('abilityNotPlayable');
        }
        if (!$handler instanceof $class) {
            self::fail('unknownAbility');
        }
        return $handler;
    }

    private static function spellsLocked(array $state, string $unitId): bool
    {
        return in_array($state['turn']['number'], $state['units'][$unitId]['flags']['spellsLockedOnTurns'] ?? [], true);
    }

    private function useAbility(array $state, string $seatId, array $payload, RandomSource $random, array &$events): array
    {
        $unitId = self::text($payload, 'unitId');
        $name = self::text($payload, 'ability');
        $targetId = self::text($payload, 'targetUnitId');
        $unit = $state['units'][$unitId] ?? null;
        if ($unit === null || $unit['hex'] === null) {
            self::fail('unknownUnit');
        }
        if ($unit['seatId'] !== $seatId) {
            self::fail('notYourUnit');
        }
        $ability = self::resolveAbility($unit, $name, TargetedAbility::class);
        if (in_array($name, $state['turn']['abilitiesUsed'][$unitId] ?? [], true)) {
            self::fail('abilityAlreadyUsed');
        }
        $blocked = $ability->unavailable($state, $unitId);
        if ($blocked !== null) {
            self::fail($blocked);
        }
        if (!in_array($unitId, $state['turn']['activated'], true)) {
            $state = $this->activate($state, $seatId, $unitId);
        }
        if (!in_array($targetId, $ability->targets($state, $unitId), true)) {
            self::fail('invalidAbilityTarget');
        }
        $state = $ability->apply($state, $unitId, $targetId, $random, $events);
        $state['turn']['abilitiesUsed'][$unitId][] = $name;
        $events[] = MatchEngine::event('abilityUsed', array_values(array_filter([$unit['hex'], $state['units'][$targetId]['hex']])), [
            'unitId' => $unitId, 'ability' => $name, 'targetUnitId' => $targetId,
        ]);
        return $state;
    }

    private function resolveChoice(array $state, string $seatId, string $kind, array $payload, RandomSource $random): array
    {
        $choice = $state['pending']['choice'] ?? null;
        if ($choice === null || $kind !== 'resolveChoice') {
            self::fail($choice === null ? 'unknownChoice' : 'choicePending');
        }
        $option = self::text($payload, 'option');
        if (self::text($payload, 'choiceId') !== $choice['choiceId'] || !in_array($option, $choice['options'], true)) {
            self::fail('unknownChoice');
        }
        if ($seatId !== $choice['seatId']) {
            if ($option !== 'keep') {
                self::fail('notYourChoice');
            }
            if ($this->now - $choice['openedAt'] < self::CHOICE_TIMEOUT_SECONDS) {
                self::fail('choiceNotExpired');
            }
        }
        $events = [];
        $state = (new StepRunner($random, $this->now))->resolve($state, $option, $events);
        $active = $state['turn']['activeSeatId'];
        if ($state['phase'] === 'active' && $state['pending'] === null && !$this->canAct($state, $active)) {
            [$state, $passed] = self::passTurn($state);
            $events = array_merge($events, $passed);
        }
        return ['state' => $state, 'events' => $events];
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
        $state['turn']['attacks'] = 0;
        $state['turn']['moveSpent'] = 0;
        $state['turn']['movedAfterAttack'] = false;
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
            if (in_array($action['kind'], ['moveUnit', 'attackUnit', 'specialAttack', 'useAbility'], true)) {
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
        $state = UnitConditions::atTurnEnd($state, $events);
        $round = $state['turn']['round'] + ($next === $state['draft']['firstSeatId'] ? 1 : 0);
        $state['turn'] = Turn::fresh($state, $next, $round);
        $events[] = MatchEngine::event('turnEnded', [], ['seatId' => $current, 'nextSeatId' => $next, 'round' => $round]);
        $state = Effects::expire($state, $next, Effects::UNTIL_NEXT_TURN, $events);
        $state = UnitConditions::atTurnStart($state, $events);
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
