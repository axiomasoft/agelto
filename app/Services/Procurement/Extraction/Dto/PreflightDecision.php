<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction\Dto;

use App\Enums\Procurement\Document\ExtractionRoute;
use App\Enums\Procurement\Document\UnprocessableReason;

/**
 * Исход `ExtractionPreflight::decide()` (D29, `docs/specs/v2/08-estimate-extraction.md` §4в):
 * закрытые состояния через приватный конструктор + фабрики (канон тестов проекта — без
 * boolean-флагов, тот же приём, что `ValidationOutcome` P3.4).
 */
final readonly class PreflightDecision
{
    private function __construct(
        public ?ExtractionRoute $route,
        public ?UnprocessableReason $unprocessableReason,
    ) {}

    public static function whole(): self
    {
        return new self(route: ExtractionRoute::Whole, unprocessableReason: null);
    }

    public static function windowed(): self
    {
        return new self(route: ExtractionRoute::Windowed, unprocessableReason: null);
    }

    public static function unprocessable(UnprocessableReason $reason): self
    {
        return new self(route: null, unprocessableReason: $reason);
    }

    public function isUnprocessable(): bool
    {
        return $this->unprocessableReason !== null;
    }
}
