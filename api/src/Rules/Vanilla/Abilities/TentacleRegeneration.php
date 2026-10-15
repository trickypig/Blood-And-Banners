<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Modes\CoopSetup;
use BloodAndBanners\Rules\Vanilla\Sides;
use BloodAndBanners\Rules\Vanilla\Water;

final class TentacleRegeneration extends SpecialAttack
{
    private function atLimit(array $state, string $unitId): bool
    {
        return count(array_filter($state['units'], static fn(array $u, string $id): bool => $u['hex'] !== null
            && Sides::allies($state, $unitId, $id) && in_array($u['name'], ['Kraken Tentacle (Lesser)', 'Kraken Tentacle (Greater)'], true), ARRAY_FILTER_USE_BOTH)) >= 4;
    }

    public function targetHexes(array $state, string $unitId): array
    {
        if ($this->atLimit($state, $unitId)) {
            return [];
        }
        $occupied = array_column($state['units'], 'hex');
        $hexes = array_values(array_filter(Hex::neighbors($state['units'][$unitId]['hex'], $state['board']), static fn(string $hex): bool =>
            Water::contains($state, $hex) && !$state['board'][$hex]['sightBlocker'] && !in_array($hex, $occupied, true)
        ));
        sort($hexes, SORT_STRING);
        return $hexes;
    }

    public function targets(array $state, string $unitId): array
    {
        return $this->targetHexes($state, $unitId) === [] ? [] : [$unitId];
    }

    public function validate(array $state, string $unitId, array $targetIds, array $options = []): ?string
    {
        if ($this->atLimit($state, $unitId)) {
            return 'tentacleLimitReached';
        }
        if ($targetIds !== [$unitId]) {
            return 'invalidAbilityTarget';
        }
        return in_array($options['toHex'] ?? '', $this->targetHexes($state, $unitId), true) ? null : 'invalidDestination';
    }

    public function steps(array $state, string $unitId, array $targetIds, array $options = []): array
    {
        return [['op' => 'summonTentacle', 'unitId' => $unitId, 'toHex' => $options['toHex']]];
    }

    public static function summon(array $state, string $sourceId, string $hex, array &$events): array
    {
        $next = $state['setup']['nextMonster'] ?? 1;
        do {
            $id = 'v-m' . $next++;
        } while (isset($state['units'][$id]));
        $state['setup']['nextMonster'] = $next;
        $monster = (new Catalog())->monster('Kraken Tentacle (Lesser)');
        $state['units'][$id] = CoopSetup::unit($monster, $id, $state['units'][$sourceId]['seatId'], $hex);
        $state['units'][$id]['side'] = Sides::ofUnit($state, $sourceId);
        $state['units'][$id]['flags']['summonedOnTurn'] = $state['turn']['number'];
        $events[] = MatchEngine::event('unitSummoned', [$hex], ['unitId' => $id, 'sourceUnitId' => $sourceId, 'name' => $monster['name'], 'hex' => $hex, 'ability' => 'Tentacle Regeneration']);
        return $state;
    }
}
