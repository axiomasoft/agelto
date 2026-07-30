<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Fixture;

use App\Services\Procurement\Contracts\ProgressReporter;

/**
 * Типизированный исход ОДНОГО элемента партии (§1 `root/service-layer-map.md`, P3.1): партия
 * продолжается даже когда отдельный элемент отклонён — сервис собирает исходы, команда их печатает
 * (через {@see ProgressReporter}) и выводит код возврата.
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
