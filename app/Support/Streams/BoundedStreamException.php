<?php

declare(strict_types=1);

namespace App\Support\Streams;

use RuntimeException;

final class BoundedStreamException extends RuntimeException
{
    public const string REASON_READ = 'read';

    public const string REASON_WRITE = 'write';

    public const string REASON_OVERFLOW = 'overflow';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
