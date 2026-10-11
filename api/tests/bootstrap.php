<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'BloodAndBanners\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($path)) {
        require $path;
    }
});

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
