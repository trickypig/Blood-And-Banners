<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Modes;

use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\MatchEngine;

/** The Void Lord's setup and the hero draft run side by side; the battle begins when both are done. */
final class CoopPrep
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function legalActions(array $state, string $seatId): array
    {
        return array_merge(
            (new CoopSetup($this->catalog))->legalActions($state, $seatId),
            (new CoopDraft($this->catalog))->legalActions($state, $seatId)
        );
    }

    public function apply(array $state, string $seatId, string $kind, array $payload): array
    {
        if (!in_array($kind, CoopSetup::KINDS, true)) {
            return (new CoopDraft($this->catalog))->apply($state, $seatId, $kind, $payload);
        }
        $result = (new CoopSetup($this->catalog))->apply($state, $seatId, $kind, $payload);
        if ($kind !== 'confirmSetup' || !CoopDraft::complete($result['state'])) {
            return $result;
        }
        [$state, $placed] = (new CoopDraft($this->catalog))->deploy($result['state']);
        $result['events'][] = MatchEngine::event('armiesPlaced', array_values($placed), ['units' => $placed]);
        return ['state' => $state, 'events' => $result['events']];
    }
}
