<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\CustomDice;
use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\Abilities\Ability;
use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;

/**
 * Resolves attacks as a queue of steps. A step may add steps in front of the queue, and may open a
 * choice, which saves the rest of the queue in $state['pending'] until resolve() continues it.
 */
final class StepRunner
{
    public function __construct(private readonly RandomSource $random, private readonly int $now)
    {
    }

    public static function strike(string $attackerId, string $targetId, ?int $power = null, ?string $ability = null, bool $double = false, ?string $damageType = null): array
    {
        return [
            'op' => 'strike', 'attackerId' => $attackerId, 'targetId' => $targetId, 'power' => $power,
            'ability' => $ability, 'double' => $double, 'damageType' => $damageType,
        ];
    }

    public static function save(string $unitId, string $sourceUnitId, string $ability, int $threshold = 11): array
    {
        return ['op' => 'save', 'unitId' => $unitId, 'sourceUnitId' => $sourceUnitId, 'ability' => $ability, 'threshold' => $threshold];
    }

    public static function poison(string $unitId, string $sourceUnitId, bool $immediate = false): array
    {
        return ['op' => 'poison', 'unitId' => $unitId, 'sourceUnitId' => $sourceUnitId, 'immediate' => $immediate];
    }

    public static function condition(string $name, string $unitId, string $sourceUnitId, array $expires): array
    {
        return ['op' => 'condition', 'name' => $name, 'unitId' => $unitId, 'sourceUnitId' => $sourceUnitId, 'expires' => $expires];
    }

    public static function teleport(string $casterId, string $unitId, string $toHex): array
    {
        return ['op' => 'teleport', 'casterId' => $casterId, 'unitId' => $unitId, 'toHex' => $toHex];
    }

    public static function splash(string $hex, string $excludeUnitId, string $ability): array
    {
        return ['op' => 'splash', 'hex' => $hex, 'excludeUnitId' => $excludeUnitId, 'ability' => $ability];
    }

    public static function chaos(string $unitId, string $targetId, string $ability): array
    {
        return ['op' => 'chaos', 'unitId' => $unitId, 'targetId' => $targetId, 'ability' => $ability];
    }

    public static function effect(string $name, string $sourceUnitId, string $targetUnitId, string $stat, int $amount, string $seatId, string $at): array
    {
        return [
            'op' => 'effect', 'name' => $name, 'sourceUnitId' => $sourceUnitId, 'targetUnitId' => $targetUnitId,
            'stat' => $stat, 'amount' => $amount, 'seatId' => $seatId, 'at' => $at,
        ];
    }

    public function run(array $state, array $steps, array &$events): array
    {
        while ($steps !== [] && $state['phase'] === 'active') {
            $step = array_shift($steps);
            [$state, $more, $choice] = $this->execute($state, $step, $events);
            $steps = array_merge($more, $steps);
            if ($choice !== null) {
                $state['pending'] = ['choice' => $choice, 'steps' => $steps];
                $events[] = MatchEngine::event('choiceOpened', [], [
                    'choiceId' => $choice['choiceId'], 'seatId' => $choice['seatId'], 'kind' => $choice['kind'], 'unitId' => $choice['unitId'],
                ]);
                return $state;
            }
        }
        return $state;
    }

    /** @return array{0: array, 1: array, 2: ?array} state, steps to run next, choice to open */
    private function execute(array $state, array $step, array &$events): array
    {
        return match ($step['op']) {
            'strike' => $this->strikeStep($state, $step, $events),
            'land' => [...$this->land($state, $step, $events), null],
            'teleport' => [$this->teleportStep($state, $step, $events), [], null],
            'save' => [$this->saveStep($state, $step, $events), [], null],
            'poison' => [Poison::apply($state, $step['unitId'], $step['sourceUnitId'], $this->random, $events, $step['immediate']), [], null],
            'condition' => [UnitConditions::apply($state, $step['name'], $step['unitId'], $step['sourceUnitId'], $step['expires'], $events), [], null],
            'splash' => [$this->splashStep($state, $step, $events), [], null],
            'chaos' => $this->chaosStep($state, $step, $events),
            'effect' => [Effects::add($state, $step['name'], $step['sourceUnitId'], $step['targetUnitId'], $step['stat'], $step['amount'], $step['seatId'], $step['at'], $events), [], null],
            default => throw new \LogicException("Unknown step {$step['op']}."),
        };
    }

    private function strikeStep(array $state, array $step, array &$events): array
    {
        $attackerId = $step['attackerId'];
        $targetId = $step['targetId'];
        if ($state['units'][$attackerId]['hex'] === null || $state['units'][$targetId]['hex'] === null) {
            return [$state, [], null];
        }
        if (AbilityRegistry::any($state['units'][$targetId], static fn(Ability $ability): bool => $ability->evades())) {
            $roll = $this->random->roll(20);
            $events[] = MatchEngine::event('evaded', [$state['units'][$targetId]['hex']], [
                'unitId' => $targetId, 'attackerId' => $attackerId, 'roll' => $roll, 'evaded' => $roll > 10,
            ]);
            if ($roll > 10) {
                return [$state, [], null];
            }
        }
        $context = ['attackerId' => $attackerId, 'defenderId' => $targetId, 'damageType' => $step['damageType'] ?? null];
        $power = Stats::effective($state, $attackerId, 'power', $context, $step['power']);
        if ($step['double']) {
            $power *= 2;
        }
        $defense = Stats::effective($state, $targetId, 'defense', $context);
        $dice = new CustomDice();
        $land = [
            'op' => 'land',
            'attackerId' => $attackerId,
            'targetId' => $targetId,
            'ability' => $step['ability'],
            'damageType' => $step['damageType'] ?? null,
            'attackFaces' => $dice->roll($power, $this->random)['faces'],
            'defenseFaces' => $dice->roll($defense, $this->random)['faces'],
            'rerolled' => null,
        ];
        $choice = $this->glareChoice($state, $land);
        if ($choice !== null) {
            $state['choiceCounter'] = (int)($state['choiceCounter'] ?? 0) + 1;
            $choice = ['choiceId' => 'choice-' . $state['choiceCounter']] + $choice;
        }
        return [$state, [$land], $choice];
    }

    /** @return array{0: array, 1: array} state, steps the attacker's abilities queue after the hit */
    private function land(array $state, array $step, array &$events): array
    {
        $swords = count(array_filter($step['attackFaces'], static fn(string $face): bool => $face === 'sword'));
        $shields = count(array_filter($step['defenseFaces'], static fn(string $face): bool => $face === 'shield'));
        $wounds = max(0, $swords - $shields);
        $payload = [
            'unitId' => $step['attackerId'],
            'targetUnitId' => $step['targetId'],
            'ability' => $step['ability'],
            'attackFaces' => $step['attackFaces'],
            'defenseFaces' => $step['defenseFaces'],
            'rerolled' => $step['rerolled'],
            'wounds' => $wounds,
        ];
        if (($step['damageType'] ?? null) !== null) {
            $payload['damageType'] = $step['damageType'];
        }
        $events[] = MatchEngine::event(
            'attackResolved',
            [$state['units'][$step['attackerId']]['hex'] ?? $state['units'][$step['targetId']]['hex'], $state['units'][$step['targetId']]['hex']],
            $payload
        );
        $state = Wounds::apply($state, $step['targetId'], $wounds, $events);
        $attacker = $state['units'][$step['attackerId']];
        if ($state['phase'] !== 'active' || $attacker['hex'] === null || $state['units'][$step['targetId']]['hex'] === null) {
            return [$state, []];
        }
        $more = [];
        foreach (AbilityRegistry::abilitiesOf($attacker) as $ability) {
            $more = array_merge($more, $ability->afterHit($state, $step['attackerId'], $step['targetId'], $wounds));
        }
        return [$state, $more];
    }

    private function teleportStep(array $state, array $step, array &$events): array
    {
        $from = $state['units'][$step['unitId']]['hex'];
        if ($from === null) {
            return $state;
        }
        $resolved = TerrainResolver::enter($state, $step['unitId'], $from, $step['toHex'], 'teleport', null, $this->random, $events);
        $state = $resolved['state'];
        $events[] = MatchEngine::event('unitTeleported', [$from, $step['toHex']], [
            'unitId' => $step['unitId'], 'casterId' => $step['casterId'], 'from' => $from, 'to' => $step['toHex'],
        ]);
        return $state;
    }

    private function saveStep(array $state, array $step, array &$events): array
    {
        $target = $state['units'][$step['unitId']];
        $source = $state['units'][$step['sourceUnitId']];
        if ($target['hex'] === null || $source['hex'] === null) {
            return $state;
        }
        $roll = $this->random->roll(20);
        $saved = $roll >= ($step['threshold'] ?? 11);
        $events[] = MatchEngine::event('saveRolled', [$target['hex']], [
            'unitId' => $step['unitId'], 'sourceUnitId' => $step['sourceUnitId'], 'ability' => $step['ability'], 'roll' => $roll, 'saved' => $saved,
        ]);
        if ($saved) {
            return $state;
        }
        $handler = AbilityRegistry::get($step['ability']);
        return $handler === null ? $state : $handler->onFailedSave($state, $step['sourceUnitId'], $step['unitId'], $roll, $this->random, $events);
    }

    private function splashStep(array $state, array $step, array &$events): array
    {
        $dice = new CustomDice();
        $hits = [];
        $ids = array_map('strval', array_keys($state['units']));
        sort($ids, SORT_STRING);
        foreach ($ids as $unitId) {
            $unit = $state['units'][$unitId];
            if ($unitId === $step['excludeUnitId'] || $unit['hex'] === null || Hex::distance($step['hex'], $unit['hex']) !== 1) {
                continue;
            }
            $roll = $dice->roll(1, $this->random);
            $hits[] = ['unitId' => $unitId, 'faces' => $roll['faces'], 'wounds' => $roll['swords']];
        }
        if ($hits === []) {
            return $state;
        }
        $events[] = MatchEngine::event('splashDamage', [$step['hex']], ['ability' => $step['ability'], 'hits' => $hits]);
        foreach ($hits as $hit) {
            $state = Wounds::apply($state, $hit['unitId'], $hit['wounds'], $events);
        }
        return $state;
    }

    private function chaosStep(array $state, array $step, array &$events): array
    {
        $unit = $state['units'][$step['unitId']];
        if ($unit['hex'] === null) {
            return [$state, [], null];
        }
        $state = Effects::add($state, $step['ability'], $step['unitId'], $step['unitId'], 'defense', -2, $unit['seatId'], Effects::UNTIL_NEXT_TURN, $events);
        $roll = $this->random->roll(20);
        $events[] = MatchEngine::event('chaosRolled', [$unit['hex']], ['unitId' => $step['unitId'], 'roll' => $roll, 'struck' => $roll >= 10]);
        return [$state, $roll >= 10 ? [self::strike($step['unitId'], $step['targetId'], null, $step['ability'], true)] : [], null];
    }

    /** A Moonlight Glare decision for whichever side of this strike has an unspent Duelist and an imperfect die. */
    private function glareChoice(array $state, array $land): ?array
    {
        foreach ([['attack', $land['attackerId'], $land['attackFaces'], 'sword'], ['defense', $land['targetId'], $land['defenseFaces'], 'shield']] as [$side, $unitId, $faces, $best]) {
            $unit = $state['units'][$unitId];
            if (!AbilityRegistry::any($unit, static fn(Ability $ability): bool => $ability->offersReroll())
                || in_array($unitId, $state['turn']['glareUsed'], true)) {
                continue;
            }
            $options = [];
            foreach ($faces as $index => $face) {
                if ($face !== $best) {
                    $options[] = "reroll:{$index}";
                }
            }
            if ($options === []) {
                continue;
            }
            return [
                'seatId' => $unit['seatId'],
                'kind' => 'moonlightGlare',
                'unitId' => $unitId,
                'side' => $side,
                'faces' => $faces,
                'options' => [...$options, 'keep'],
                'openedAt' => $this->now,
            ];
        }
        return null;
    }

    public function resolve(array $state, string $option, array &$events): array
    {
        $pending = $state['pending'];
        $choice = $pending['choice'];
        $steps = $pending['steps'];
        $state['pending'] = null;
        $events[] = MatchEngine::event('choiceResolved', [], ['choiceId' => $choice['choiceId'], 'option' => $option]);
        if (str_starts_with($option, 'reroll:')) {
            $index = (int)substr($option, 7);
            $key = $choice['side'] === 'attack' ? 'attackFaces' : 'defenseFaces';
            $from = $steps[0][$key][$index];
            $to = (new CustomDice())->roll(1, $this->random)['faces'][0];
            $steps[0][$key][$index] = $to;
            $steps[0]['rerolled'] = ['side' => $choice['side'], 'index' => $index, 'from' => $from, 'to' => $to];
            $state['turn']['glareUsed'][] = $choice['unitId'];
        }
        return $this->run($state, $steps, $events);
    }
}
