<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Conditions;

final class Slipping extends Condition
{
    public function blocksDisengagementStrike(): bool
    {
        return true;
    }
}
