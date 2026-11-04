<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

use Carbon\CarbonImmutable;

/**
 * Подпись цифры расхода: откуда взята, с каким лагом, за
 * какой горизонт и на какой момент. Значения ВЫЧИСЛЯЮТСЯ реализацией источника, а не вписываются
 * литералами — иначе подпись соврёт первой при смене `AI_RETENTION_LLM_CALLS_MONTHS`.
 */
final readonly class UsageSignature
{
    public function __construct(
        public string $sourceId,
        public string $label,
        public int $lagSeconds,
        public ?int $horizonMonths,
        public ?CarbonImmutable $horizonFrom,
        public CarbonImmutable $asOf,
    ) {
        if (($horizonMonths === null) !== ($horizonFrom === null) || $horizonMonths !== null && $horizonMonths < 0 || $lagSeconds < 0) {
            throw new \InvalidArgumentException('Invalid usage retention signature.');
        }
    }

    /**
     * Одна строка подписи для всех потребителей (решение владельца по гейту контракта,
     * 2026-07-30): консоль, `/pulse` и виджет дашборда не собирают её каждый по-своему —
     * иначе «лаг 0» на одном экране и «лаг: нет» на другом описывали бы один и тот же факт
     * разными словами.
     */
    public function toLine(): string
    {
        $lag = $this->lagSeconds === 0
            ? 'лаг 0'
            : sprintf('лаг ~%d ч', (int) ceil($this->lagSeconds / 3600));

        $horizon = $this->horizonMonths === null ? 'без удаления по возрасту'
            : sprintf('горизонт %d мес. (с %s)', $this->horizonMonths, $this->horizonFrom?->toDateString());

        return sprintf(
            'Источник: %s · %s · %s · актуально на %s',
            $this->label,
            $lag,
            $horizon,
            $this->asOf->toDateTimeString(),
        );
    }
}
