<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Conditions;

final class Petrified extends Condition
{
    public function blocksMovement(): bool
    {
        return true;
    }

    public function blocksAttacks(): bool
    {
        return true;
    }

    public function blocksDefense(): bool
    {
        return true;
    }

    public function blocksDisengagementStrike(): bool
    {
        return true;
    }
}
