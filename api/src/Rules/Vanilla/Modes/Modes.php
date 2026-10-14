<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Modes;

use BloodAndBanners\Game\GameServiceException;
use BloodAndBanners\Rules\Vanilla\Catalog;

final class Modes
{
    public static function for(string $mode, ?Catalog $catalog = null): ModeRules
    {
        return match ($mode) {
            'vanillaPvp' => new VanillaMode($catalog),
            default => throw new GameServiceException('modeNotPlayable', 'That game mode cannot be played in this version yet.'),
        };
    }

    public static function ofState(array $state, ?Catalog $catalog = null): ModeRules
    {
        return self::for((string)($state['mode'] ?? 'vanillaPvp'), $catalog);
    }
}
