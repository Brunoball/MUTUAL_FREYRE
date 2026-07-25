<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'VALIDATION_ERROR',
        public readonly int $status = 422,
        public readonly array $fields = []
    ) {
        parent::__construct($message);
    }
}
