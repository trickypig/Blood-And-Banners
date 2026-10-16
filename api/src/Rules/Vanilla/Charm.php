<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

/**
 * Siren's Song: on its owner's turn a charmed unit must end its move on the reachable hexes nearest its Mermaid,
 * before any other unit of that seat acts. The charm ends with that activation or when the Mermaid dies.
 */
final class Charm
{
    public const CONDITION = 'Charmed';

    /** The living Mermaid holding $unitId, if it is charmed. */
    public static function source(array $state, string $unitId): ?string
    {
        $charm = UnitConditions::named($state, $unitId, self::CONDITION);
        $source = $charm['sourceUnitId'] ?? null;
        return $source !== null && ($state['units'][$source]['hex'] ?? null) !== null ? $source : null;
    }

    /** The movement options a charmed unit may take: those ending nearest its Mermaid, never farther than it stands. */
    public static function allowed(array $state, string $unitId, array $options): array
    {
        $source = self::source($state, $unitId);
        if ($source === null) {
            return $options;
        }
        $mermaid = $state['units'][$source]['hex'];
        $best = Hex::distance($state['units'][$unitId]['hex'], $mermaid);
        foreach ($options as $option) {
            $best = min($best, Hex::distance($option['destination'], $mermaid));
        }
        return array_values(array_filter($options, static fn(array $option): bool => Hex::distance($option['destination'], $mermaid) === $best));
    }

    /** Whether the options include a hex closer to the Mermaid than where the charmed unit stands. */
    public static function canApproach(array $state, string $unitId, array $options): bool
    {
        $source = self::source($state, $unitId);
        if ($source === null) {
            return false;
        }
        $allowed = self::allowed($state, $unitId, $options);
        $mermaid = $state['units'][$source]['hex'];
        return $allowed !== [] && Hex::distance($allowed[0]['destination'], $mermaid) < Hex::distance($state['units'][$unitId]['hex'], $mermaid);
    }

    /** Drop charms whose Mermaid has died, and the active seat's charms whose activation is over. */
    public static function cleanup(array $state, array &$events): array
    {
        foreach ($state['conditions'] ?? [] as $condition) {
            if ($condition['name'] !== self::CONDITION) {
                continue;
            }
            $unitId = $condition['unitId'];
            $spent = $state['units'][$unitId]['seatId'] === $state['turn']['activeSeatId']
                && in_array($unitId, $state['turn']['activated'], true)
                && $state['turn']['activeUnitId'] !== $unitId;
            if ($spent || self::source($state, $unitId) === null) {
                $state = UnitConditions::clear($state, $unitId, self::CONDITION, $events);
            }
        }
        return $state;
    }
}
