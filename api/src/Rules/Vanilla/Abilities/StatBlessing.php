<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\Effects;
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\Targeting;

/** Apply a timed stat change to one unit in reach. Reach null = the user's effective Range. */
final class StatBlessing extends TargetedAbility
{
    public const ALLIES = 'allies';
    public const ANYONE = 'anyone';

    public function __construct(
        string $name,
        private readonly string $stat,
        private readonly int $amount,
        private readonly string $expires,
        private readonly ?int $reach,
        private readonly string $who,
        private readonly bool $includeSelf,
    ) {
        parent::__construct($name);
    }

    public function targets(array $state, string $unitId): array
    {
        $reach = $this->reach ?? Stats::effective($state, $unitId, 'range');
        return $this->who === self::ALLIES
            ? Targeting::alliesWithin($state, $unitId, $reach, $this->includeSelf)
            : Targeting::unitsWithin($state, $unitId, $reach, $this->includeSelf);
    }

    public function apply(array $state, string $unitId, string $targetId, RandomSource $random, array &$events): array
    {
        return Effects::add($state, $this->name, $unitId, $targetId, $this->stat, $this->amount, $state['units'][$unitId]['seatId'], $this->expires, $events);
    }
}
