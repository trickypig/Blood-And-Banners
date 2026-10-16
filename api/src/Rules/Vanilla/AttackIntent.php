<?php
declare(strict_types=1);
namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;
use BloodAndBanners\Rules\Vanilla\Abilities\PanPipes;
use BloodAndBanners\Rules\Vanilla\Abilities\SpecialAttack;

/** Keeps a targeted attack intact while Pan Pipes chooses a replacement before any dice or effects. */
final class AttackIntent
{
    public function __construct(private readonly RandomSource $random, private readonly int $now) {}

    public function run(array $state, array $step, array &$events): array
    {
        $attacker = $step['attackerId'];
        if ($state['units'][$attacker]['hex'] === null) {
            return [$state, [], null];
        }
        for ($i = $step['checked'] ?? 0; $i < count($step['targetIds']); $i++) {
            $target = $step['targetIds'][$i];
            if ($state['units'][$target]['hex'] === null) {
                return [$state, [], null];
            }
            $protector = PanPipes::protector($state, $attacker, $target);
            if ($protector === null) {
                continue;
            }
            $roll = $this->random->roll(20);
            $events[] = MatchEngine::event('saveRolled', [$state['units'][$attacker]['hex']], [
                'unitId' => $attacker, 'sourceUnitId' => $protector, 'ability' => 'Pan Pipes', 'roll' => $roll, 'saved' => $roll >= 15,
            ]);
            if ($roll >= 15) {
                continue;
            }
            $candidates = self::redirectTargets($state, $step, $i);
            if ($candidates === []) {
                $events[] = MatchEngine::event('attackRedirected', [], ['unitId' => $attacker, 'targetUnitId' => null, 'ability' => 'Pan Pipes']);
                return [$state, [], null];
            }
            $step['checked'] = $i + 1;
            $state['choiceCounter'] = (int)($state['choiceCounter'] ?? 0) + 1;
            $choice = [
                'choiceId' => 'choice-' . $state['choiceCounter'], 'kind' => 'panPipes', 'unitId' => $attacker,
                'seatId' => $state['units'][$protector]['seatId'], 'targetIndex' => $i, 'openedAt' => $this->now,
                'options' => array_map(static fn(string $id): string => 'target:' . $id, $candidates),
                'targetHexes' => array_map(static fn(string $id): string => $state['units'][$id]['hex'], $candidates),
            ];
            return [$state, [$step], $choice];
        }
        $ability = AbilityRegistry::get($step['ability'] ?? '');
        $steps = $ability instanceof SpecialAttack
            ? $ability->steps($state, $attacker, $step['targetIds'], $step['options'] ?? [])
            : [StepRunner::strike($attacker, $step['targetIds'][0])];
        return [$state, $steps, null];
    }

    private static function redirectTargets(array $state, array $step, int $index): array
    {
        $attacker = $step['attackerId'];
        $original = $state['units'][$step['targetIds'][$index]];
        $ability = AbilityRegistry::get($step['ability'] ?? '');
        $ids = [];
        foreach ($state['units'] as $id => $unit) {
            $id = (string)$id;
            if ($id === $attacker || $unit['hex'] === null || !Sides::allies($state, $attacker, $id)
                || Combat::protectedFrom($state, $attacker, $id)) {
                continue;
            }
            $targets = $step['targetIds'];
            $targets[$index] = $id;
            if (count(array_unique($targets)) !== count($targets)) {
                continue;
            }
            if ($ability instanceof SpecialAttack) {
                // Reuse the attack's range, sight and shape checks while permitting compelled friendly fire.
                $view = $state;
                // Costs are already paid; validate the in-flight attack using its pre-use availability.
                if (isset($step['availability'])) {
                    $view['units'][$attacker]['flags'] = $step['availability']['flags'];
                    $view['turn']['abilitiesUsed'][$attacker] = $step['availability']['used'];
                }
                foreach ($targets as $candidate) {
                    if (Sides::allies($state, $attacker, $candidate)) {
                        $view['units'][$candidate]['side'] = $original['side'];
                        $view['units'][$candidate]['seatId'] = $original['seatId'];
                    }
                }
                $valid = $ability->validate($view, $attacker, $targets, $step['options'] ?? []) === null;
            } else {
                $valid = Combat::targetError($state, $attacker, $id) === null;
            }
            if ($valid) {
                $ids[] = $id;
            }
        }
        sort($ids, SORT_STRING);
        return $ids;
    }
}
