<?php
declare(strict_types=1);

namespace BloodAndBanners\Account;

use RuntimeException;

/** An account or friends error that maps directly to an HTTP status and stable error code. */
final class AccountException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
