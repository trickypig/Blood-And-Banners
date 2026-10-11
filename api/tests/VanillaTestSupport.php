<?php
declare(strict_types=1);

use BloodAndBanners\Game\GameServiceException;
use BloodAndBanners\Rules\Vanilla\Catalog;

function test_catalog(): Catalog
{
    return new Catalog(__DIR__ . '/fixtures/catalog.json');
}

function expect_game_error(string $code, callable $action): void
{
    try {
        $action();
    } catch (GameServiceException $error) {
        expect_same($code, $error->errorCode, "expected game error {$code}");
        return;
    }
    throw new RuntimeException("expected game error {$code} but the action succeeded");
}
