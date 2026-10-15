<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

/** Multiple Heads counts remaining HP; Many Heads starts at four and loses one per two wounds. */
final class HydraHeads extends Ability
{
    public function __construct(string $name, private readonly ?int $initialHeads = null, private readonly int $woundsPerHead = 1)
    {
        parent::__construct($name);
    }

    public function attacksPerActivation(array $unit = [], array $context = []): int
    {
        return max(0, ($this->initialHeads ?? $unit['hp'] ?? 1) - intdiv((int)($unit['wounds'] ?? 0), $this->woundsPerHead));
    }
}
