<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

function expect_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expect_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . sprintf(' (expected %s, got %s)', var_export($expected, true), var_export($actual, true)));
    }
}
