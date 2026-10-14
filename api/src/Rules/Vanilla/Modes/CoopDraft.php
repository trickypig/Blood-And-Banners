<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Modes;

use BloodAndBanners\Game\GameServiceException;
use BloodAndBanners\Rules\Vanilla\Catalog;

final class CoopDraft
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function legalActions(array $state, string $seatId): array
    {
        return [];
    }

    public function apply(array $state, string $seatId, string $kind, array $payload): array
    {
        throw new GameServiceException('unsupportedCommand', 'This command is not legal in the current game phase.');
    }
}
