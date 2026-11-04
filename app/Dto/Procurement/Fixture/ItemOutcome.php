<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Fixture;

use App\Services\Procurement\Contracts\ProgressReporter;

/**
 * Типизированный исход одного элемента партии. Отклонение элемента не останавливает
 * партию: сервис собирает исходы, а команда показывает их через {@see ProgressReporter}
 * и выбирает код завершения.
 */
final readonly class ItemOutcome
{
    public function __construct(
        public string $code,
        public string $status,
        public ?string $reason = null,
    ) {}

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }
}
