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

    public static function strike(string $attackerId, string $targetId, ?int $power = null, ?string $ability = null, bool $double = false): array
    {
        return ['op' => 'strike', 'attackerId' => $attackerId, 'targetId' => $targetId, 'power' => $power, 'ability' => $ability, 'double' => $double];
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
            'land' => [$this->land($state, $step, $events), [], null],
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
        $context = ['attackerId' => $attackerId, 'defenderId' => $targetId];
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
            'attackFaces' => $dice->roll($power, $this->random)['faces'],
            'defenseFaces' => $dice->roll($defense, $this->random)['faces'],
            'rerolled' => null,
        ];
        return [$state, [$land], null];
    }

    private function land(array $state, array $step, array &$events): array
    {
        $swords = count(array_filter($step['attackFaces'], static fn(string $face): bool => $face === 'sword'));
        $shields = count(array_filter($step['defenseFaces'], static fn(string $face): bool => $face === 'shield'));
        $wounds = max(0, $swords - $shields);
        $events[] = MatchEngine::event(
            'attackResolved',
            [$state['units'][$step['attackerId']]['hex'] ?? $state['units'][$step['targetId']]['hex'], $state['units'][$step['targetId']]['hex']],
            [
                'unitId' => $step['attackerId'],
                'targetUnitId' => $step['targetId'],
                'ability' => $step['ability'],
                'attackFaces' => $step['attackFaces'],
                'defenseFaces' => $step['defenseFaces'],
                'rerolled' => $step['rerolled'],
                'wounds' => $wounds,
            ]
        );
        return Wounds::apply($state, $step['targetId'], $wounds, $events);
    }
}
