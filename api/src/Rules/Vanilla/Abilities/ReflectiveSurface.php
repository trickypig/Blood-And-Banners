<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

/** Reflects targeted attack damage only; splash and other effects keep their original targets. */
final class ReflectiveSurface extends Ability
{
    public function reflectionThreshold(): ?int
    {
        return 16;
    }
}
