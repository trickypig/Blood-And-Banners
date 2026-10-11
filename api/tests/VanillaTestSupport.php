<?php
declare(strict_types=1);

use BloodAndBanners\Game\GameServiceException;
use BloodAndBanners\Rules\Vanilla\Board;
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

function proving_field_board(): array
{
    $definition = json_decode(
        (string)file_get_contents(dirname(__DIR__) . '/scenarios/proving-field.json'),
        true,
        flags: JSON_THROW_ON_ERROR
    );
    return Board::fromScenario($definition);
}
