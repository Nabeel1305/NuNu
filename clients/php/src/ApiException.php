<?php

namespace OfflinePayments;

class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $body = [],
    ) {
        parent::__construct($message, $status);
    }
}
