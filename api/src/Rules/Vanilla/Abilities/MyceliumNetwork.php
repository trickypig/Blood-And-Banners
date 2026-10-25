<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Board;
use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\Conditions\Condition;
use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Sides;
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\Terrain;
use BloodAndBanners\Rules\Vanilla\TerrainResolver;
use BloodAndBanners\Rules\Vanilla\UnitConditions;

final class MyceliumNetwork extends MovementAbility
{
    private const MUSHROOMS = ['Spore Sprout', 'Fungal Archer', 'Shroom Bomb', 'Shroom Kicker', 'Myconid'];

    public function unavailable(array $state, string $unitId): ?string
    {
        if (UnitConditions::any($state, $unitId, static fn(Condition $c): bool => $c->blocksMovement())) {
            return 'unitStunned';
        }
        $turn = $state['turn'];
        if (!in_array($unitId, $turn['activated'], true)) {
            return null;
        }
        return $turn['activeUnitId'] === $unitId && !$turn['moved'] && $turn['moveSpent'] === 0 && $turn['attacks'] === 0
            ? null : 'networkNeedsStillness';
    }

    public function options(array $state, string $unitId): array
    {
        $anchors = array_filter($state['units'], static fn(array $u, string $id): bool => $id !== $unitId && $u['hex'] !== null
            && !Sides::enemies($state, $unitId, $id) && in_array($u['name'], self::MUSHROOMS, true), ARRAY_FILTER_USE_BOTH);
        $occupied = array_column($state['units'], 'hex');
        $options = [];
        foreach ($state['board'] as $hex => $tile) {
            if (Board::impassable($tile) || in_array($hex, $occupied, true)) {
                continue;
            }
            foreach ($anchors as $anchor) {
                if (Hex::distance($hex, $anchor['hex']) !== 1) {
                    continue;
                }
                $options[] = [
                    'destination' => $hex, 'path' => [$hex], 'cost' => Stats::effective($state, $unitId, 'move'),
                    'hazards' => Terrain::hazards($state, $unitId, $hex, 'teleport'),
                ];
                break;
            }
        }
        return $options;
    }

    public function apply(array $state, string $unitId, string $toHex, RandomSource $random, array &$events): array
    {
        $from = $state['units'][$unitId]['hex'];
        $state['turn']['moved'] = true;
        $state['turn']['moveSpent'] = Stats::effective($state, $unitId, 'move');
        $state = TerrainResolver::enter($state, $unitId, $from, $toHex, 'teleport', null, $random, $events)['state'];
        $events[] = MatchEngine::event('unitTeleported', [$from, $toHex], ['unitId' => $unitId, 'casterId' => $unitId, 'ability' => $this->name, 'from' => $from, 'to' => $toHex]);
        return $state;
    }
}
