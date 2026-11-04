<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts\Dto;

/**
 * Один тик прогресса длинной операции.
 */
final readonly class ProgressTick
{
    public function __construct(
        public int $done,
        public int $total,
        public string $label,
        public ?string $note = null,
    ) {}
}
