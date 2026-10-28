<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

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
