<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\RandomSource;

/**
 * Carried items. A player unit that ends its own move on a potion is offered a choice: drink it now, keep it in
 * its inventory (up to MAX), or leave it where it lies. Pushes and teleports never offer it.
 */
final class Items
{
    public const MAX = 2;

    /** @return list<string> the potion kinds the unit carries */
    public static function carried(array $state, string $unitId): array
    {
        return $state['units'][$unitId]['items'] ?? [];
    }

    /** Units that cannot act, and the Void Lord's monsters, never pick up or use items. */
    public static function blocked(array $state, string $unitId): bool
    {
        foreach (['Stunned', 'Petrified', 'Entrapped', Charm::CONDITION] as $name) {
            if (UnitConditions::has($state, $unitId, $name)) {
                return true;
            }
        }
        return ($state['sides'][Sides::ofUnit($state, $unitId)]['kind'] ?? null) === 'void';
    }

    public static function potionUnder(array $state, string $unitId): ?string
    {
        $hex = $state['units'][$unitId]['hex'];
        $kind = $hex === null ? null : ($state['board'][$hex]['object'] ?? null);
        return $kind !== null && isset(Potions::KINDS[$kind]) ? $kind : null;
    }

    public static function usedThisTurn(array $state, string $unitId): bool
    {
        return in_array($unitId, $state['turn']['itemsUsed'] ?? [], true);
    }

    /** A unit may pick up the potion it stands on instead of moving, but not after it has moved or attacked. */
    public static function canPickUp(array $state, string $unitId, bool $active): bool
    {
        if (self::blocked($state, $unitId) || self::potionUnder($state, $unitId) === null || count(self::carried($state, $unitId)) >= self::MAX) {
            return false;
        }
        $turn = $state['turn'];
        return !$active || (!$turn['moved'] && $turn['attacks'] === 0 && $turn['moveSpent'] === 0);
    }

    /** One item per unit per turn; after attacking only a unit that may still move (a stinger) can use one. */
    public static function canUse(array $state, string $unitId, bool $active, bool $canMove): bool
    {
        if (self::blocked($state, $unitId) || self::carried($state, $unitId) === [] || self::usedThisTurn($state, $unitId)) {
            return false;
        }
        return !$active || $state['turn']['attacks'] === 0 || $canMove;
    }

    public static function pickUp(array $state, string $unitId, array &$events): array
    {
        $hex = $state['units'][$unitId]['hex'];
        $kind = (string)self::potionUnder($state, $unitId);
        $state['board'][$hex]['object'] = null;
        $state['units'][$unitId]['items'][] = $kind;
        $state['turn']['moved'] = true;
        $events[] = MatchEngine::event('itemPickedUp', [$hex], [
            'unitId' => $unitId, 'seatId' => $state['units'][$unitId]['seatId'], 'hex' => $hex, 'kind' => $kind,
        ]);
        return $state;
    }

    public static function use(array $state, string $unitId, string $kind, array &$events): array
    {
        $items = $state['units'][$unitId]['items'];
        unset($items[array_search($kind, $items, true)]);
        $state['units'][$unitId]['items'] = array_values($items);
        $state['turn']['itemsUsed'][] = $unitId;
        return Potions::applyEffect($state, $unitId, $state['units'][$unitId]['hex'], $kind, $events);
    }

    /** A defeated carrier drops one random item on its hex if that hex holds no map object; the rest are lost. */
    public static function dropOnDefeat(array $state, string $unitId, string $hex, ?RandomSource $random, array &$events): array
    {
        $items = self::carried($state, $unitId);
        if ($items === []) {
            return $state;
        }
        $chosen = count($items) > 1 && $random !== null ? $random->roll(count($items)) - 1 : 0;
        if (($state['board'][$hex]['object'] ?? null) === null) {
            $state['board'][$hex]['object'] = $items[$chosen];
            $events[] = MatchEngine::event('itemDropped', [$hex], ['unitId' => $unitId, 'hex' => $hex, 'kind' => $items[$chosen]]);
            unset($items[$chosen]);
        }
        foreach ($items as $kind) {
            $events[] = MatchEngine::event('itemLost', [$hex], ['unitId' => $unitId, 'kind' => $kind]);
        }
        $state['units'][$unitId]['items'] = [];
        return $state;
    }

    /** Call once the unit's own move has ended and nothing is pending. Opens the pickup choice if one applies. */
    public static function offerPickup(array $state, string $unitId, array &$events): array
    {
        if ($state['phase'] !== 'active' || $state['pending'] !== null || $state['units'][$unitId]['hex'] === null
            || self::blocked($state, $unitId)) {
            return $state;
        }
        $kind = self::potionUnder($state, $unitId);
        if ($kind === null || count(self::carried($state, $unitId)) >= self::MAX) {
            return $state;
        }
        $options = self::usedThisTurn($state, $unitId) ? ['keep', 'leave'] : ['drink', 'keep', 'leave'];
        $state['choiceCounter'] = (int)($state['choiceCounter'] ?? 0) + 1;
        $choice = [
            'choiceId' => 'choice-' . $state['choiceCounter'],
            'seatId' => $state['units'][$unitId]['seatId'],
            'kind' => 'itemPickup',
            'unitId' => $unitId,
            'item' => $kind,
            'options' => $options,
            'targetHexes' => [$state['units'][$unitId]['hex']],
            'openedAt' => time(),
        ];
        $state['pending'] = ['choice' => $choice, 'context' => []];
        $events[] = MatchEngine::event('choiceOpened', [], [
            'choiceId' => $choice['choiceId'], 'seatId' => $choice['seatId'],
            'kind' => $choice['kind'], 'unitId' => $unitId,
        ]);
        return $state;
    }

    public static function resolvePickup(array $state, string $option, array &$events): array
    {
        $choice = $state['pending']['choice'];
        $unitId = (string)$choice['unitId'];
        $hex = $state['units'][$unitId]['hex'];
        $kind = (string)$choice['item'];
        $seatId = (string)$choice['seatId'];
        $state['pending'] = null;
        if ($option === 'drink') {
            $state['board'][$hex]['object'] = null;
            $state = Potions::applyEffect($state, $unitId, $hex, $kind, $events);
            $state['turn']['itemsUsed'][] = $unitId;
        } elseif ($option === 'keep') {
            $state['board'][$hex]['object'] = null;
            $state['units'][$unitId]['items'][] = $kind;
            $events[] = MatchEngine::event('itemPickedUp', [$hex], ['unitId' => $unitId, 'seatId' => $seatId, 'hex' => $hex, 'kind' => $kind]);
        }
        $events[] = MatchEngine::event('choiceResolved', [], ['choiceId' => $choice['choiceId'], 'option' => $option]);
        return $state;
    }
}
