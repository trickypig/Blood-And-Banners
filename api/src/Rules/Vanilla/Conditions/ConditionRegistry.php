<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Conditions;

final class ConditionRegistry
{
    /** @var array<string, Condition>|null */
    private static ?array $handlers = null;

    public static function get(string $name): ?Condition
    {
        if (self::$handlers === null) {
            self::$handlers = [];
            foreach ([new Stunned('Stunned'), new Poisoned('Poisoned')] as $handler) {
                self::$handlers[$handler->name] = $handler;
            }
        }
        return self::$handlers[$name] ?? null;
    }
}
