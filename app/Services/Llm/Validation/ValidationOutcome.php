<?php

declare(strict_types=1);

namespace App\Services\Llm\Validation;

use Spatie\LaravelData\Data;

/**
 * Исход валидационного каскада `StructuredResponseValidator`/`ReaskPolicy` (ТЗ 04 §2/§6):
 * sealed-подобный value object с тремя закрытыми состояниями через приватный конструктор +
 * фабричные методы (канон тестов проекта — без boolean-флагов).
 * - ok: гидратированный `Data`-класс прошёл финишную/JSON/схемную/структурную проверку.
 * - retryable: поправимо — синтаксис/семантика/MAX_TOKENS; `ReaskPolicy` решает re-ask или
 *   эскалацию маршрутом, MAX_TOKENS бабблится наверх без re-ask (оркестратору — уменьшить окно).
 * - fatal: неопределённость (Safety/Other finishReason) — `ReaskPolicy` эскалирует маршрутом
 *   немедленно, без re-ask на том же маршруте.
 */
final readonly class ValidationOutcome
{
    private function __construct(
        public bool $ok,
        public ?Data $data = null,
        public ?string $reason = null,
        public bool $retryable = false,
    ) {}

    public static function ok(Data $data): self
    {
        return new self(ok: true, data: $data);
    }

    public static function retryable(string $reason): self
    {
        return new self(ok: false, reason: $reason, retryable: true);
    }

    public static function fatal(string $reason): self
    {
        return new self(ok: false, reason: $reason, retryable: false);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function isRetryable(): bool
    {
        return ! $this->ok && $this->retryable;
    }

    public function isFatal(): bool
    {
        return ! $this->ok && ! $this->retryable;
    }
}
