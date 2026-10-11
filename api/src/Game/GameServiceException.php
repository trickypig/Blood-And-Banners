<?php
declare(strict_types=1);

namespace BloodAndBanners\Game;

use DomainException;

final class GameServiceException extends DomainException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $currentVersion = null,
        public readonly ?string $recovery = null,
    ) {
        parent::__construct($message);
    }
}
