<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts\Dto;

/**
 * Пара «подпись → значение» сводки анализа. Денежные value передаются строкой
 * с десятичным представлением, чтобы отображение не теряло точность через float.
 */
final readonly class SummaryMetric
{
    public function __construct(
        public string $label,
        public string|int|float $value,
        public ?string $unit = null,
    ) {}
}
