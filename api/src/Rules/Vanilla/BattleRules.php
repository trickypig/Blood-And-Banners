<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Game\GameServiceException;
use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\Abilities\Ability;
use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;
use BloodAndBanners\Rules\Vanilla\Abilities\SpecialAttack;
use BloodAndBanners\Rules\Vanilla\Abilities\TargetedAbility;
use BloodAndBanners\Rules\Vanilla\Abilities\MovementAbility;
use BloodAndBanners\Rules\Vanilla\Conditions\Condition;
use BloodAndBanners\Rules\Vanilla\Modes\Modes;

final class BattleRules
{
    public const CHOICE_TIMEOUT_SECONDS = 45;

    private const KINDS = ['activateUnit', 'moveUnit', 'moveAbility', 'attackUnit', 'specialAttack', 'useAbility', 'resolveChoice', 'endActivation', 'endTurn'];
    private const MESSAGES = [
        'heroesActivationRequired' => 'While your heroes live, your other units are limited this turn; activate a hero.',
        'unitCannotAttack' => 'That unit cannot attack while petrified or entrapped.',
        'unitImmobilized' => 'That unit cannot move while petrified or entrapped.',
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
        'movementPathRequired' => 'Choose the exact route through that terrain.',
        'invalidMovementPath' => 'That movement route is no longer legal.',
        'invalidTerrainDestination' => 'That unit cannot voluntarily stop on that terrain.',
        'invalidSlipChoice' => 'That ice movement choice is no longer legal.',
        'targetOutOfRange' => 'That target is out of range.',
        'targetSubmerged' => 'That unit is submerged and immune to ranged attacks.',
        'charmedUnitFirst' => 'A charmed unit must move toward its Mermaid before anything else this turn.',
        'charmedMustApproach' => 'A charmed unit must end its move as close as possible to its Mermaid.',
        'unitCharmed' => 'A charmed unit cannot use abilities.',
        'targetBurrowed' => 'That unit is burrowed and immune to ranged attacks until it is engaged.',
        'unitSummonedThisTurn' => 'A summoned unit can activate starting on its next turn.',
        'tentacleLimitReached' => 'There can be at most four living allied Kraken tentacles.',
        'noLineOfSight' => 'That target is not in line of sight.',
        'unknownAbility' => 'That unit does not have that ability.',
        'abilityNotPlayable' => 'That ability is not yet in the digital game.',
        'abilityLocked' => 'Fragile Focus: that unit cannot cast spells this turn.',
        'columnAlreadyTossed' => 'That Treant has already tossed its column and cannot use either column attack again.',
        'abilityCoolingDown' => 'That attack is cooling down and cannot be used this turn.',
        'invalidAbilityTarget' => 'That is not a valid target for this ability.',
        'chainTooLong' => 'Chain Lightning can only travel 5 hexes in total.',
        'gustNeedsStillness' => 'Gust of Wind must be used instead of moving.',
        'networkNeedsStillness' => 'Mycelium Network must replace the whole movement before attacking.',
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
            if (self::charmHolds($state, $seatId) !== null) {
                self::fail('charmedUnitFirst');
            }
            [$state, $events] = self::passTurn($state, $random);
            return ['state' => $state, 'events' => $events];
        }

        $events = [];
        if ($kind === 'activateUnit') {
            $state = $this->activate($state, $seatId, self::text($payload, 'unitId'), $random, $events);
        } elseif ($kind === 'moveUnit') {
            $state = $this->move($state, $seatId, $payload, $random, $events);
        } elseif ($kind === 'moveAbility') {
            $state = $this->moveAbility($state, $seatId, $payload, $random, $events);
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
            if (self::mustApproach($state, $unitId)) {
                self::fail('charmedMustApproach');
            }
            $state['turn']['activeUnitId'] = null;
        }

        if ($state['phase'] === 'active') {
            $state = Charm::cleanup($state, $events);
        }
        if ($state['phase'] === 'active' && $state['pending'] === null && !$this->canAct($state, $seatId)) {
            [$state, $passed] = self::passTurn($state, $random);
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
            if (($choice['kind'] ?? null) !== 'moonlightGlare') {
                return $seatId === $choice['seatId']
                    ? [[
                        'kind' => 'resolveChoice', 'unitId' => $choice['unitId'],
                        'targetHexes' => $choice['targetHexes'] ?? [],
                    ]]
                    : [];
            }
            $mayKeep = $this->now - $choice['openedAt'] >= self::CHOICE_TIMEOUT_SECONDS;
            return $seatId === $choice['seatId'] || $mayKeep
                ? [['kind' => 'resolveChoice', 'unitId' => $choice['unitId'], 'targetHexes' => []]]
                : [];
        }
        if ($state['turn']['activeSeatId'] !== $seatId) {
            return [];
        }
        $actions = [];
        $held = self::charmHolds($state, $seatId);
        foreach ($state['units'] as $unitId => $unit) {
            $unitId = (string)$unitId;
            if ($unit['seatId'] !== $seatId || $unit['hex'] === null || ($held !== null && $held !== $unitId)) {
                continue;
            }
            $charmed = Charm::source($state, $unitId) !== null;
            $active = $state['turn']['activeUnitId'] === $unitId;
            $activated = in_array($unitId, $state['turn']['activated'], true);
            $canStart = !$activated && $this->activationError($state, $unitId) === null;
            if (!$active && $canStart && UnitConditions::has($state, $unitId, 'Slipping')) {
                $actions[] = ['kind' => 'activateUnit', 'unitId' => $unitId, 'targetHexes' => []];
                continue;
            }
            if (($activated || $canStart) && !$charmed) {
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
            foreach (AbilityRegistry::abilitiesOf($unit) as $ability) {
                if ($charmed || !$ability instanceof MovementAbility || $ability->unavailable($state, $unitId) !== null) {
                    continue;
                }
                $options = $ability->options($state, $unitId);
                if ($options !== []) {
                    $actions[] = ['kind' => 'moveAbility', 'unitId' => $unitId, 'ability' => $ability->name,
                        'targetHexes' => array_column($options, 'destination'), 'movementOptions' => $options];
                }
            }
            if (self::canMove($state, $unitId)) {
                $budget = Stats::effective($state, $unitId, 'move') - ($active ? $state['turn']['moveSpent'] : 0);
                $options = Charm::allowed($state, $unitId, Movement::options($state, $unitId, $budget));
                if ($options !== []) {
                    $actions[] = [
                        'kind' => 'moveUnit', 'unitId' => $unitId,
                        'targetHexes' => array_column($options, 'destination'),
                        'movementOptions' => $options,
                    ];
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
            if (self::attacksLeft($state, $unitId, true) && !self::spellsLocked($state, $unitId)) {
                foreach (AbilityRegistry::abilitiesOf($unit) as $ability) {
                    if (!$ability instanceof SpecialAttack || $ability->unavailable($state, $unitId) !== null) {
                        continue;
                    }
                    $hexes = $ability->targetHexes($state, $unitId);
                    if ($hexes !== []) {
                        $actions[] = [
                            'kind' => 'specialAttack',
                            'unitId' => $unitId,
                            'ability' => $ability->name,
                            'targetHexes' => $hexes,
                            ...(($selection = $ability->selection($state, $unitId)) === null ? [] : ['targetSelection' => $selection]),
                        ];
                    }
                }
            }
        }
        if ($state['turn']['activeUnitId'] !== null && !self::mustApproach($state, $state['turn']['activeUnitId'])) {
            $actions[] = ['kind' => 'endActivation', 'unitId' => $state['turn']['activeUnitId'], 'targetHexes' => []];
        }
        if ($held === null) {
            $actions[] = ['kind' => 'endTurn', 'unitId' => null, 'targetHexes' => []];
        }
        return $actions;
    }

    private function move(array $state, string $seatId, array $payload, RandomSource $random, array &$events): array
    {
        $unitId = self::text($payload, 'unitId');
        $state = $this->activate($state, $seatId, $unitId, $random, $events);
        if ($state['phase'] !== 'active' || $state['pending'] !== null || $state['units'][$unitId]['hex'] === null) {
            return $state;
        }
        if (UnitConditions::has($state, $unitId, 'Petrified') || UnitConditions::has($state, $unitId, 'Entrapped')) {
            self::fail('unitImmobilized');
        }
        if (self::rooted($state, $unitId)) {
            self::fail('unitStunned');
        }
        if (!self::canMove($state, $unitId)) {
            self::fail('alreadyMoved');
        }
        $budget = Stats::effective($state, $unitId, 'move') - $state['turn']['moveSpent'];
        $option = $this->movementOption($state, $unitId, $payload, $budget);
        if (!in_array($option['destination'], array_column(Charm::allowed($state, $unitId, Movement::options($state, $unitId, $budget)), 'destination'), true)) {
            self::fail('charmedMustApproach');
        }
        $to = $option['destination'];
        $path = $option['path'];
        $from = $state['units'][$unitId]['hex'];
        $hits = [];
        $evades = AbilityRegistry::any($state['units'][$unitId], static fn(Ability $ability): bool => $ability->evades());
        $voluntaryPath = [];
        foreach ($path as $hex) {
            $voluntaryPath[] = $hex;
            if (Terrain::type($state['board'][$hex]) === 'ice' && !Terrain::ignored($state, $unitId, 'ice', 'voluntary')) {
                break;
            }
        }
        foreach (Movement::disengagedFromPath($state, $unitId, $voluntaryPath) as $enemyId) {
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
        $consequences = [];
        $remaining = $state['units'][$unitId]['hp'] - $state['units'][$unitId]['wounds'];
        $killer = null;
        foreach ($hits as $hit) {
            $remaining -= $hit['wounds'];
            if ($remaining <= 0) {
                $killer = $hit['byUnitId'];
                break;
            }
        }
        $state = Wounds::apply($state, $unitId, array_sum(array_column($hits, 'wounds')), $consequences, $random, $killer, directAction: true);
        if ($state['units'][$unitId]['hex'] === null || $state['phase'] !== 'active') {
            $events[] = MatchEngine::event('unitMoved', [$from], [
                'unitId' => $unitId, 'from' => $from, 'to' => $from, 'path' => [], 'disengagement' => $hits,
            ]);
            array_push($events, ...$consequences);
            return $state;
        }

        $entered = [];
        $previous = $from;
        $spent = 0;
        foreach ($path as $index => $hex) {
            [$fromQ, $fromR] = Hex::parse($previous);
            [$toQ, $toR] = Hex::parse($hex);
            $spent += Terrain::stepCost($state, $unitId, $previous, $hex, 'voluntary');
            $resolved = TerrainResolver::enter($state, $unitId, $previous, $hex, 'voluntary', [$toQ - $fromQ, $toR - $fromR], $random, $events);
            $state = $resolved['state'];
            $entered[] = $hex;
            $previous = $hex;
            if ($resolved['stop'] || $state['phase'] !== 'active' || $state['units'][$unitId]['hex'] === null) {
                break;
            }
            if (Terrain::type($state['board'][$hex]) === 'ice' && UnitConditions::has($state, $unitId, 'Slipping')) {
                $slide = IceMovement::continue($state, $unitId, $budget - $spent, $random, $events);
                $state = $slide['state'];
                array_push($entered, ...$slide['path']);
                if ($slide['pending'] === null
                    && $state['phase'] === 'active'
                    && $state['units'][$unitId]['hex'] !== null
                    && $slide['path'] !== array_slice($path, $index + 1)) {
                    self::fail('invalidMovementPath');
                }
                break;
            }
        }
        if ($state['phase'] !== 'active' || $state['units'][$unitId]['hex'] === null) {
            return $state;
        }
        $to = $state['units'][$unitId]['hex'];
        $state['turn']['moveSpent'] += $option['cost'];
        if ($state['turn']['attacks'] > 0) {
            $state['turn']['movedAfterAttack'] = true;
        } else {
            $state['turn']['moved'] = true;
        }
        $events[] = MatchEngine::event('unitMoved', [$from, $to], [
            'unitId' => $unitId,
            'from' => $from,
            'to' => $to,
            'path' => $entered,
            'disengagement' => $hits,
        ]);
        if ($state['pending'] === null) {
            $state = Crystals::claim($state, $unitId, $events);
        }
        return self::closeIfSpent($state, $unitId);
    }

    private function moveAbility(array $state, string $seatId, array $payload, RandomSource $random, array &$events): array
    {
        $unitId = self::text($payload, 'unitId');
        $name = self::text($payload, 'ability');
        if (Charm::source($state, $unitId) !== null) {
            self::fail('unitCharmed');
        }
        $state = $this->activate($state, $seatId, $unitId, $random, $events);
        if ($state['phase'] !== 'active' || $state['pending'] !== null || $state['units'][$unitId]['hex'] === null) {
            return $state;
        }
        $ability = self::resolveAbility($state['units'][$unitId], $name, MovementAbility::class);
        $blocked = $ability->unavailable($state, $unitId);
        if ($blocked !== null) {
            self::fail($blocked);
        }
        $to = self::text($payload, 'toHex');
        if (!in_array($to, array_column($ability->options($state, $unitId), 'destination'), true)) {
            self::fail('invalidDestination');
        }
        return $ability->apply($state, $unitId, $to, $random, $events);
    }

    private function movementOption(array $state, string $unitId, array $payload, int $budget): array
    {
        if (array_key_exists('path', $payload)) {
            $path = $payload['path'];
            if (!is_array($path) || $path === [] || count($path) !== count(array_unique($path))) {
                self::fail('invalidMovementPath');
            }
            foreach ($path as $hex) {
                if (!is_string($hex) || !isset($state['board'][$hex])) {
                    self::fail('invalidMovementPath');
                }
            }
            $destination = $path[array_key_last($path)];
            if (!Terrain::canEnd($state, $unitId, $destination, 'voluntary')) {
                self::fail('invalidTerrainDestination');
            }
            $option = Movement::canonical($state, $unitId, $destination, $budget);
            if ($option === null || $option['path'] !== array_values($path)) {
                self::fail('invalidMovementPath');
            }
            return $option;
        }

        $destination = self::text($payload, 'to');
        $option = Movement::canonical($state, $unitId, $destination, $budget);
        if ($option === null) {
            self::fail('hexUnreachable');
        }
        if ($option['hazards'] !== []) {
            self::fail('movementPathRequired');
        }
        return $option;
    }

    private function attack(array $state, string $seatId, array $payload, RandomSource $random, array &$events): array
    {
        $unitId = self::text($payload, 'unitId');
        $targetId = self::text($payload, 'targetUnitId');
        $state = $this->activate($state, $seatId, $unitId, $random, $events);
        if ($state['phase'] !== 'active' || $state['pending'] !== null || $state['units'][$unitId]['hex'] === null) {
            return $state;
        }
        if (UnitConditions::any($state, $unitId, static fn(Condition $c): bool => $c->blocksAttacks())) {
            self::fail('unitCannotAttack');
        }
        if (!self::attacksLeft($state, $unitId)) {
            self::fail('noAttacksLeft');
        }
        $target = $state['units'][$targetId] ?? null;
        if ($target === null || $target['hex'] === null || !Sides::enemies($state, $unitId, $targetId)) {
            self::fail('unknownUnit');
        }
        $blocked = Combat::targetError($state, $unitId, $targetId);
        if ($blocked !== null) {
            self::fail($blocked);
        }
        $state['turn']['attacks']++;
        $state = self::closeIfSpent($state, $unitId);
        return (new StepRunner($random, $this->now))->run($state, [['op' => 'attackIntent', 'attackerId' => $unitId, 'targetIds' => [$targetId], 'ability' => null]], $events);
    }

    private static function attackAllowance(array $unit, array $context): int
    {
        $allowance = 1;
        foreach (AbilityRegistry::abilitiesOf($unit) as $ability) {
            $allowance = max($allowance, $ability->attacksPerActivation($unit, $context));
        }
        return $allowance;
    }

    /** Whether $unitId may still attack, as the active unit or as a fresh activation. */
    private static function attacksLeft(array $state, string $unitId, bool $specialAttack = false): bool
    {
        if (UnitConditions::any($state, $unitId, static fn(Condition $c): bool => $c->blocksAttacks())) {
            return false;
        }
        if ($state['turn']['activeUnitId'] !== $unitId) {
            return true;
        }
        return $state['turn']['attacks'] < self::attackAllowance($state['units'][$unitId], [
            'specialAttack' => $specialAttack,
            'specialAttackUsed' => $state['turn']['specialAttackUsed'] ?? false,
            'abilitiesUsed' => $state['turn']['abilitiesUsed'][$unitId] ?? [],
        ]);
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
        if (!self::attacksLeft($state, $unitId) && !self::attacksLeft($state, $unitId, true)
            && !($state['turn']['attacks'] > 0 && self::canMove($state, $unitId))) {
            $state['turn']['activeUnitId'] = null;
        }
        return $state;
    }

    private function specialAttack(array $state, string $seatId, array $payload, RandomSource $random, array &$events): array
    {
        $unitId = self::text($payload, 'unitId');
        $name = self::text($payload, 'ability');
        $targets = array_values(array_filter($payload['targetUnitIds'] ?? [], 'is_string'));
        $state = $this->activate($state, $seatId, $unitId, $random, $events);
        if ($state['phase'] !== 'active' || $state['pending'] !== null || $state['units'][$unitId]['hex'] === null) {
            return $state;
        }
        if (UnitConditions::any($state, $unitId, static fn(Condition $c): bool => $c->blocksAttacks())) {
            self::fail('unitCannotAttack');
        }
        $ability = self::resolveAbility($state['units'][$unitId], $name, SpecialAttack::class);
        if (($unavailable = $ability->unavailable($state, $unitId)) !== null) {
            self::fail($unavailable);
        }
        if (self::spellsLocked($state, $unitId)) {
            self::fail('abilityLocked');
        }
        if (!self::attacksLeft($state, $unitId, true)) {
            self::fail('noAttacksLeft');
        }
        $options = ['toHex' => self::text($payload, 'toHex')];
        $error = $ability->validate($state, $unitId, $targets, $options);
        if ($error !== null) {
            self::fail($error);
        }
        $state['turn']['attacks']++;
        $state['turn']['specialAttackUsed'] = true;
        $steps = [['op' => 'attackIntent', 'attackerId' => $unitId, 'targetIds' => $targets, 'ability' => $name, 'options' => $options,
            'availability' => ['flags' => $state['units'][$unitId]['flags'], 'used' => $state['turn']['abilitiesUsed'][$unitId] ?? []]]];
        $state = $ability->onUse($state, $unitId);
        $state = self::closeIfSpent($state, $unitId);
        return (new StepRunner($random, $this->now))->run($state, $steps, $events);
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
        if (Charm::source($state, $unitId) !== null) {
            self::fail('unitCharmed');
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
            $state = $this->activate($state, $seatId, $unitId, $random, $events);
        }
        if ($state['phase'] !== 'active' || $state['pending'] !== null || $state['units'][$unitId]['hex'] === null) {
            return $state;
        }
        $unit = $state['units'][$unitId];
        if (!in_array($targetId, $ability->targets($state, $unitId), true)) {
            self::fail('invalidAbilityTarget');
        }
        $state['turn']['abilitiesUsed'][$unitId][] = $name;
        $events[] = MatchEngine::event('abilityUsed', [$unit['hex'], $state['units'][$targetId]['hex']], [
            'unitId' => $unitId, 'ability' => $name, 'targetUnitId' => $targetId,
        ]);
        return $ability->apply($state, $unitId, $targetId, $random, $events);
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
        if (($choice['kind'] ?? null) === 'iceCollision') {
            if ($seatId !== $choice['seatId']) {
                self::fail('notYourChoice');
            }
            $remainingBeforeChoice = (int)($state['pending']['context']['remainingMove'] ?? 0);
            $events = [];
            $resolved = IceMovement::resolveChoice($state, $option, $random, $events);
            $state = $resolved['state'];
            $activateAfter = (bool)($resolved['activateAfter'] ?? false);
            // The slide that paused was the unit's own move; now that it has ended, it may claim crystals.
            if (!$activateAfter && $state['pending'] === null && $state['phase'] === 'active' && $state['units'][$choice['unitId']]['hex'] !== null) {
                $state = Crystals::claim($state, $choice['unitId'], $events);
            }
            if (!$activateAfter && ($state['turn']['activeUnitId'] ?? null) === $choice['unitId']) {
                $state['turn']['moveSpent'] += max(0, $remainingBeforeChoice - $resolved['remainingMove']);
            }
            if ($activateAfter
                && $state['phase'] === 'active'
                && $state['pending'] === null
                && $state['units'][$choice['unitId']]['hex'] !== null) {
                $unitId = $choice['unitId'];
                $move = Stats::effective($state, $unitId, 'move');
                $state['turn']['activeUnitId'] = $unitId;
                $state['turn']['activated'][] = $unitId;
                $state['turn']['moved'] = true;
                $state['turn']['attacks'] = 0;
                $state['turn']['specialAttackUsed'] = false;
                $state['turn']['moveSpent'] = $move - $resolved['remainingMove'];
                $state['turn']['movedAfterAttack'] = false;
            }
            $active = $state['turn']['activeSeatId'];
            if ($state['phase'] === 'active' && $state['pending'] === null && !$this->canAct($state, $active)) {
                [$state, $passed] = self::passTurn($state, $random);
                $events = array_merge($events, $passed);
            }
            return ['state' => $state, 'events' => $events];
        }
        if ($seatId !== $choice['seatId']) {
            if (($choice['kind'] ?? null) !== 'moonlightGlare' || $option !== 'keep') {
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
            [$state, $passed] = self::passTurn($state, $random);
            $events = array_merge($events, $passed);
        }
        return ['state' => $state, 'events' => $events];
    }

    private function activate(array $state, string $seatId, string $unitId, RandomSource $random, array &$events): array
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
        foreach (AbilityRegistry::abilitiesOf($unit) as $ability) {
            $state = $ability->onActivate($state, $unitId, $events);
        }
        $wasSlipping = UnitConditions::has($state, $unitId, 'Slipping');
        $move = Stats::effective($state, $unitId, 'move');
        // Set before the slide so a slide paused on a choice still remembers where the activation began.
        $state['turn']['startHex'] = $unit['hex'];
        $slide = IceMovement::beforeActivation($state, $unitId, $random, $events);
        $state = $slide['state'];
        if ($state['phase'] !== 'active' || $state['pending'] !== null || $state['units'][$unitId]['hex'] === null) {
            return $state;
        }
        $state['turn']['activeUnitId'] = $unitId;
        $state['turn']['activated'][] = $unitId;
        $state['turn']['moved'] = $wasSlipping;
        $state['turn']['attacks'] = 0;
        $state['turn']['specialAttackUsed'] = false;
        $state['turn']['moveSpent'] = $wasSlipping ? $move - $slide['remainingMove'] : 0;
        $state['turn']['movedAfterAttack'] = false;
        return $state;
    }

    private function activationError(array $state, string $unitId): ?string
    {
        if (($state['units'][$unitId]['flags']['summonedOnTurn'] ?? null) === $state['turn']['number']) {
            return 'unitSummonedThisTurn';
        }
        if (in_array($unitId, $state['turn']['activated'], true)) {
            return 'unitAlreadyActivated';
        }
        if (Turn::activationsRemaining($state) <= 0) {
            return 'noActivationsRemaining';
        }
        $held = self::charmHolds($state, $state['units'][$unitId]['seatId']);
        if ($held !== null && $held !== $unitId) {
            return 'charmedUnitFirst';
        }
        return Modes::ofState($state)->activationError($state, $unitId);
    }

    /** The seat's charmed unit that must still walk toward its Mermaid before anything else happens, if any. */
    private static function charmHolds(array $state, string $seatId): ?string
    {
        foreach ($state['units'] as $unitId => $unit) {
            if ($unit['seatId'] === $seatId && $unit['hex'] !== null && self::mustApproach($state, (string)$unitId)) {
                return (string)$unitId;
            }
        }
        return null;
    }

    private static function mustApproach(array $state, string $unitId): bool
    {
        if (Charm::source($state, $unitId) === null || !self::canMove($state, $unitId)) {
            return false;
        }
        $spent = $state['turn']['activeUnitId'] === $unitId ? $state['turn']['moveSpent'] : 0;
        return Charm::canApproach($state, $unitId, Movement::options($state, $unitId, Stats::effective($state, $unitId, 'move') - $spent));
    }

    private function canAct(array $state, string $seatId): bool
    {
        foreach ($this->legalActions($state, $seatId) as $action) {
            if (in_array($action['kind'], ['activateUnit', 'moveUnit', 'moveAbility', 'attackUnit', 'specialAttack', 'useAbility'], true)) {
                return true;
            }
        }
        return false;
    }

    private static function passTurn(array $state, RandomSource $random): array
    {
        $current = $state['turn']['activeSeatId'];
        $events = [];
        $state = Effects::expire($state, $current, Effects::UNTIL_END_OF_TURN, $events);
        $state = TerrainResolver::atTurnEnd($state, $current, $random, $events);
        if ($state['phase'] !== 'active') {
            return [$state, $events];
        }
        $state = UnitConditions::atTurnEnd($state, $events);
        foreach ($state['units'] as $unitId => $unit) {
            if ($unit['seatId'] !== $current || $unit['hex'] === null) {
                continue;
            }
            foreach (AbilityRegistry::abilitiesOf($unit) as $ability) {
                $state = $ability->onTurnEnd($state, (string)$unitId, $events);
                if ($ability instanceof SpecialAttack) {
                    $state = $ability->afterTurn($state, (string)$unitId);
                }
            }
        }
        $slot = (int)$state['turn']['slot'];
        $nextSlot = Schedule::after($state, $slot);
        $next = Modes::ofState($state)->seatAtSlot($state, $nextSlot);
        $round = $state['turn']['round'] + ($nextSlot <= $slot ? 1 : 0);
        $state['turn'] = Turn::fresh($state, $next, $round, $nextSlot);
        $events[] = MatchEngine::event('turnEnded', [], ['seatId' => $current, 'nextSeatId' => $next, 'round' => $round]);
        $state = Effects::expire($state, $next, Effects::UNTIL_NEXT_TURN, $events);
        $state = UnitConditions::atTurnStart($state, $events);
        return [$state, $events];
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
