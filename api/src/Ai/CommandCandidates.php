<?php
declare(strict_types=1);

namespace BloodAndBanners\Ai;

use BloodAndBanners\Rules\Vanilla\Hex;

/**
 * Expands legal actions into concrete command payloads shaped like the ones GameTable.razor sends.
 * Some expansions are guesses (a special attack aimed at its own caster, say); the engine rejects
 * those and the Simulator drops them.
 */
final class CommandCandidates
{
    /** @return list<array{kind:string, payload:array}> */
    public static function for(array $state, string $seatId, array $legalActions): array
    {
        $occupant = [];
        foreach ($state['units'] as $id => $unit) {
            if ($unit['hex'] !== null) {
                $occupant[(string)$unit['hex']] = (string)$id;
            }
        }
        $out = [];
        $seen = [];
        $add = static function (string $kind, array $payload) use (&$out, &$seen): void {
            $key = $kind . json_encode($payload);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = ['kind' => $kind, 'payload' => $payload];
            }
        };
        foreach ($legalActions as $action) {
            $unitId = isset($action['unitId']) ? (string)$action['unitId'] : null;
            $hexes = array_values(array_map('strval', $action['targetHexes'] ?? []));
            $targets = array_values(array_filter(array_map(static fn (string $h): ?string => $occupant[$h] ?? null, $hexes)));
            switch ($action['kind']) {
                case 'activateUnit':
                    $add('activateUnit', ['unitId' => $unitId]);
                    break;
                case 'moveUnit':
                    foreach ($action['movementOptions'] ?? [] as $option) {
                        $add('moveUnit', ['unitId' => $unitId, 'to' => (string)$option['destination'], 'path' => array_values($option['path'])]);
                    }
                    break;
                case 'moveAbility':
                    foreach ($action['movementOptions'] ?? [] as $option) {
                        $payload = ['unitId' => $unitId, 'ability' => (string)$action['ability'], 'toHex' => (string)$option['destination']];
                        if (isset($option['path'])) {
                            $payload['path'] = array_values($option['path']);
                        }
                        $add('moveAbility', $payload);
                    }
                    break;
                case 'attackUnit':
                    foreach ($targets as $target) {
                        $add('attackUnit', ['unitId' => $unitId, 'targetUnitId' => $target]);
                    }
                    break;
                case 'specialAttack':
                    foreach (self::specialPayloads($state, $action, (string)$unitId, $hexes, $targets, $occupant) as $payload) {
                        $add('specialAttack', $payload);
                    }
                    break;
                case 'useAbility':
                    foreach ($targets as $target) {
                        $add('useAbility', ['unitId' => $unitId, 'ability' => (string)$action['ability'], 'targetUnitId' => $target]);
                    }
                    break;
                case 'pickUpItem':
                    $add('pickUpItem', ['unitId' => $unitId]);
                    break;
                case 'useItem':
                    $add('useItem', ['unitId' => $unitId, 'item' => (string)$action['item']]);
                    break;
                case 'resolveChoice':
                    $choice = $state['pending']['choice'] ?? null;
                    if ($choice === null) {
                        break;
                    }
                    // Someone else's expired Moonlight Glare may only be kept.
                    $options = $choice['seatId'] === $seatId ? $choice['options'] : array_intersect($choice['options'], ['keep']);
                    foreach ($options as $option) {
                        $add('resolveChoice', ['choiceId' => (string)$choice['choiceId'], 'option' => (string)$option]);
                    }
                    break;
                case 'endActivation':
                    $add('endActivation', ['unitId' => $unitId]);
                    break;
                case 'endTurn':
                    $add('endTurn', []);
                    break;
            }
        }
        return $out;
    }

    /** Single targets, the caster itself, each target hex as toHex, and nearest multi-target combinations. */
    private static function specialPayloads(array $state, array $action, string $unitId, array $hexes, array $targets, array $occupant): array
    {
        $ability = (string)$action['ability'];
        $base = ['unitId' => $unitId, 'ability' => $ability];
        $payloads = [];
        foreach ($targets as $target) {
            $payloads[] = $base + ['targetUnitIds' => [$target]];
        }
        $payloads[] = $base + ['targetUnitIds' => [$unitId]];
        foreach ($hexes as $hex) {
            if (!isset($occupant[$hex])) {
                $payloads[] = $base + ['targetUnitIds' => [$unitId], 'toHex' => $hex];
            }
        }
        $selection = $action['targetSelection'] ?? null;
        $max = (int)($selection['maxTargets'] ?? 1);
        if ($selection !== null && $max > 1 && $targets !== []) {
            $from = (string)$state['units'][$unitId]['hex'];
            $byDistance = static function (array $ids) use ($state, $from): array {
                usort($ids, static fn (string $a, string $b): int =>
                    [Hex::distance($from, (string)$state['units'][$a]['hex']), $a] <=> [Hex::distance($from, (string)$state['units'][$b]['hex']), $b]);
                return $ids;
            };
            if (($selection['mode'] ?? null) === 'adjacentEnemies') {
                $secondary = (array)($selection['secondaryTargets'] ?? []);
                foreach ($targets as $primary) {
                    $near = array_values(array_filter(array_map(
                        static fn (string $h): ?string => $occupant[$h] ?? null,
                        array_map('strval', (array)($secondary[$state['units'][$primary]['hex']] ?? []))
                    ), static fn (?string $id): bool => $id !== null && $id !== $primary));
                    $near = $byDistance($near);
                    for ($k = 1; $k < $max && $k <= count($near); $k++) {
                        $payloads[] = $base + ['targetUnitIds' => array_merge([$primary], array_slice($near, 0, $k))];
                    }
                }
            } else {
                $sorted = $byDistance($targets);
                for ($k = 2; $k <= min($max, count($sorted)); $k++) {
                    $payloads[] = $base + ['targetUnitIds' => array_slice($sorted, 0, $k)];
                }
            }
        }
        return $payloads;
    }
}
