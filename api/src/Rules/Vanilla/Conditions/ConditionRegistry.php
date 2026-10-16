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
            foreach ([new PanPipes('Pan Pipes'), new Stunned('Stunned'), new Petrified('Petrified'), new Entrapped('Entrapped'), new Slowed('Slowed'), new Poisoned('Poisoned'), new Unsteady('Unsteady'), new Slipping('Slipping'), new Charmed('Charmed'), new Hibernating('Hibernating'), new Enraged('Enraged')] as $handler) {
                self::$handlers[$handler->name] = $handler;
            }
        }
        return self::$handlers[$name] ?? null;
    }
}
