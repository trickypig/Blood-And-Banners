<?php
declare(strict_types=1);
namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\CustomDice;
use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\TerrainResolver;
use BloodAndBanners\Rules\Vanilla\Wounds;

final class SkyToss extends SaveAttack
{
    public function __construct(string $name)
    {
        parent::__construct($name, range: 1, threshold: 15);
    }

    public function targets(array $state, string $unitId): array
    {
        return array_values(array_filter(parent::targets($state, $unitId), static fn(string $id): bool => self::destinations($state, $id) !== []));
    }

    public function failureSteps(array $state, string $unitId, string $targetId): array
    {
        return [['op' => 'skyTossChoice', 'attackerId' => $unitId, 'targetId' => $targetId]];
    }

    public static function destinations(array $state, string $targetId): array
    {
        $origin = $state['units'][$targetId]['hex'];
        if ($origin === null) {
            return [];
        }
        $occupied = array_column($state['units'], 'hex');
        $destinations = [];
        foreach ($state['board'] as $key => $hex) {
            $key = (string)$key;
            if (!$hex['sightBlocker'] && !in_array($key, $occupied, true) && Hex::distance($origin, $key) <= 4) {
                $destinations[] = $key;
            }
        }
        sort($destinations, SORT_STRING);
        return $destinations;
    }

    public static function land(array $state, array $step, RandomSource $random, array &$events): array
    {
        $id = $step['targetId'];
        $from = $state['units'][$id]['hex'];
        if ($from === null) {
            return $state;
        }
        // A toss flies over intervening obstacles. Landing applies terrain but never disengagement.
        $state = TerrainResolver::enter($state, $id, $from, $step['toHex'], 'teleport', null, $random, $events)['state'];
        if ($state['phase'] !== 'active' || $state['units'][$id]['hex'] === null) {
            return $state;
        }
        $roll = (new CustomDice())->roll(3, $random);
        $events[] = MatchEngine::event('unitTossed', [$from, $step['toHex']], [
            'unitId' => $id, 'sourceUnitId' => $step['attackerId'], 'from' => $from, 'to' => $step['toHex'],
            'faces' => $roll['faces'], 'wounds' => $roll['swords'],
        ]);
        return Wounds::apply($state, $id, $roll['swords'], $events, $random, $step['attackerId']);
    }
}
