<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Conditions;

/** Siren's Song: no attacks or parting strikes; Charm enforces the march toward the Mermaid. */
final class Charmed extends Condition
{
    public function blocksAttacks(): bool
    {
        return true;
    }

    public function blocksDisengagementStrike(): bool
    {
        return true;
    }
}
