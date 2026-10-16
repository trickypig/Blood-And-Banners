<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Hex;

/** Molten Shell and Darkness Veil: each landed strike deals one wound less, optionally only from more than one hex away. */
final class DamageReduction extends Ability
{
    public function __construct(string $name, private readonly bool $rangedOnly = false)
    {
        parent::__construct($name);
    }

    public function woundsPrevented(array $state, string $ownerId, ?string $originHex): int
    {
        if (!$this->rangedOnly) {
            return 1;
        }
        $here = $state['units'][$ownerId]['hex'];
        return $originHex !== null && $here !== null && Hex::distance($originHex, $here) > 1 ? 1 : 0;
    }
}
