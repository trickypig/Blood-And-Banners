<?php
declare(strict_types=1);
namespace BloodAndBanners\Scenario;

final class MapException extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
