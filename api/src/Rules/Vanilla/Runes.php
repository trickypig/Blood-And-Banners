<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

/**
 * Nexus-point runes. Every unit standing within a rune's reach (radius 1 = its own hex, each step adds a ring,
 * height ignored), on any side, gets its bonus. A kind counts once however many of its runes reach the unit.
 */
final class Runes
{
    public const KINDS = ['rune-of-strength' => 'Rune of Strength', 'rune-of-focus' => 'Rune of Focus', 'rune-of-warding' => 'Rune of Warding'];
    public const MAX_RADIUS = 4;

    /**
     * @return list<array{source: string, amount: int}>
     */
    public static function statSources(array $state, string $unitId, string $stat, array $context): array
    {
        $hex = $state['units'][$unitId]['hex'] ?? null;
        if ($hex === null || !in_array($stat, ['power', 'defense'], true)) {
            return [];
        }
        $sources = [];
        foreach (self::coveringKinds($state, $hex) as $kind) {
            if (self::applies($kind, $unitId, $stat, $context)) {
                $sources[] = ['source' => self::KINDS[$kind], 'amount' => 1];
            }
        }
        return $sources;
    }

    /** The rune kinds whose reach includes the hex, each once, in KINDS order. */
    public static function coveringKinds(array $state, string $hex): array
    {
        $found = [];
        foreach ($state['board'] as $key => $tile) {
            $kind = $tile['object'] ?? null;
            if ($kind !== null && isset(self::KINDS[$kind]) && Hex::distance((string)$key, $hex) <= self::radius($tile) - 1) {
                $found[$kind] = true;
            }
        }
        return array_values(array_filter(array_keys(self::KINDS), static fn(string $kind): bool => isset($found[$kind])));
    }

    private static function radius(array $tile): int
    {
        return max(1, min(self::MAX_RADIUS, (int)($tile['radius'] ?? 1)));
    }

    private static function applies(string $kind, string $unitId, string $stat, array $context): bool
    {
        $attacking = $stat === 'power' && ($context['attackerId'] ?? null) === $unitId;
        $special = ($context['ability'] ?? null) !== null;
        return match ($kind) {
            'rune-of-strength' => $attacking && !$special,
            'rune-of-focus' => $attacking && $special,
            'rune-of-warding' => $stat === 'defense',
        };
    }
}
