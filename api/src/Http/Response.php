<?php
declare(strict_types=1);

namespace BloodAndBanners\Http;

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly array $headers = ['content-type' => 'application/json; charset=utf-8'],
    ) {
    }
}
