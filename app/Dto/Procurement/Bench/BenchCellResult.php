<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Bench;

/**
 * Ячейка бенчмарка `(эталон, вариант представления)`. Применимая ячейка всегда несёт
 * `representation`, `preflight`, `predicted` и общий для прогона `prompt_version_set`.
 * `actual`, `quality`, `outcome` заполняются реальным прогоном; dry-run оставляет их `null`.
 * Фабрики и приватный конструктор задают допустимые сочетания этих состояний.
 */
final readonly class BenchCellResult
{
    private function __construct(
        public string $fixtureCode,
        public string $variant,
        public bool $applicable,
        /** @var array{kind: ?string, tool: ?string, size_bytes: ?int, page_count: ?int, token_estimate: ?int}|null */
        public ?array $representation,
        /** @var array{route: ?string, reason: ?string}|null */
        public ?array $preflight,
        /** @var array{input_tokens: ?int, input_cost_micros: ?int}|null */
        public ?array $predicted,
        /** @var array<string, int>|null */
        public ?array $promptVersionSet,
        /** @var array{prompt_tokens: int, cached_tokens: int, completion_tokens: int, total_tokens: int, cost_micros: int, calls: int, failed_calls: int, duration_ms: int}|null */
        public ?array $actual,
        /** @var array{overall: array{precision: float, recall: float, f1: float}, per_field: array<string, array{precision: float, recall: float, f1: float}>, row_coverage: float}|null */
        public ?array $quality,
        /** @var array{status: string, last_error: ?string}|null */
        public ?array $outcome,
    ) {}

    public static function notApplicable(string $fixtureCode, string $variant): self
    {
        return new self(
            fixtureCode: $fixtureCode,
            variant: $variant,
            applicable: false,
            representation: null,
            preflight: null,
            predicted: null,
            promptVersionSet: null,
            actual: null,
            quality: null,
            outcome: null,
        );
    }

    /**
     * @param  array{kind: ?string, tool: ?string, size_bytes: ?int, page_count: ?int, token_estimate: ?int}  $representation
     * @param  array{route: ?string, reason: ?string}  $preflight
     * @param  array{input_tokens: ?int, input_cost_micros: ?int}  $predicted
     * @param  array<string, int>  $promptVersionSet
     */
    public static function dry(
        string $fixtureCode,
        string $variant,
        array $representation,
        array $preflight,
        array $predicted,
        array $promptVersionSet,
    ): self {
        return new self(
            fixtureCode: $fixtureCode,
            variant: $variant,
            applicable: true,
            representation: $representation,
            preflight: $preflight,
            predicted: $predicted,
            promptVersionSet: $promptVersionSet,
            actual: null,
            quality: null,
            outcome: null,
        );
    }

    /**
     * @param  array{kind: ?string, tool: ?string, size_bytes: ?int, page_count: ?int, token_estimate: ?int}  $representation
     * @param  array{route: ?string, reason: ?string}  $preflight
     * @param  array{input_tokens: ?int, input_cost_micros: ?int}  $predicted
     * @param  array<string, int>  $promptVersionSet
     * @param  array{prompt_tokens: int, cached_tokens: int, completion_tokens: int, total_tokens: int, cost_micros: int, calls: int, failed_calls: int, duration_ms: int}  $actual
     * @param  array{overall: array{precision: float, recall: float, f1: float}, per_field: array<string, array{precision: float, recall: float, f1: float}>, row_coverage: float}|null  $quality
     * @param  array{status: string, last_error: ?string}  $outcome
     */
    public static function executed(
        string $fixtureCode,
        string $variant,
        array $representation,
        array $preflight,
        array $predicted,
        array $promptVersionSet,
        array $actual,
        ?array $quality,
        array $outcome,
    ): self {
        return new self(
            fixtureCode: $fixtureCode,
            variant: $variant,
            applicable: true,
            representation: $representation,
            preflight: $preflight,
            predicted: $predicted,
            promptVersionSet: $promptVersionSet,
            actual: $actual,
            quality: $quality,
            outcome: $outcome,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fixture_code' => $this->fixtureCode,
            'variant' => $this->variant,
            'applicable' => $this->applicable,
            'prompt_version_set' => $this->promptVersionSet,
            'representation' => $this->representation,
            'preflight' => $this->preflight,
            'predicted' => $this->predicted,
            'actual' => $this->actual,
            'quality' => $this->quality,
            'outcome' => $this->outcome,
        ];
    }
}
