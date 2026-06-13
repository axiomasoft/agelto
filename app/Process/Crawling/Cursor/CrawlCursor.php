<?php

declare(strict_types=1);

namespace App\Process\Crawling\Cursor;

/**
 * Курсор обхода краулера: явный стек невыполненных ценовых интервалов + текущий день и страница.
 *
 * Заменяет рекурсию дробления по ценам (стек вызовов PHP) на персистентное состояние —
 * после краша браузера обход можно возобновить с последней точки (незавершённый интервал + страница).
 * Сериализуется в Crawler.state / plt_crawler_runs.
 */
final class CrawlCursor
{
    /**
     * @param  list<array{from: float, to: float}>  $priceStack
     */
    public function __construct(
        public ?string $day = null,
        public array $priceStack = [],
        public int $page = 1,
    ) {}

    /**
     * @param  array<string, mixed>|null  $state
     */
    public static function fromState(?array $state): self
    {
        $state ??= [];

        $stack = [];
        foreach ($state['price_stack'] ?? [] as $interval) {
            $stack[] = ['from' => (float) $interval['from'], 'to' => (float) $interval['to']];
        }

        return new self(
            day: $state['day'] ?? null,
            priceStack: $stack,
            page: max(1, (int) ($state['page'] ?? 1)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toState(): array
    {
        return [
            'day' => $this->day,
            'price_stack' => $this->priceStack,
            'page' => $this->page,
        ];
    }

    public function setDay(?string $day): void
    {
        $this->day = $day;
    }

    /**
     * Кладёт интервал на вершину стека (для последующей обработки).
     */
    public function pushInterval(float $from, float $to): void
    {
        $this->priceStack[] = ['from' => $from, 'to' => $to];
    }

    /**
     * Снимает интервал с вершины стека (LIFO). null — стек пуст.
     *
     * @return array{from: float, to: float}|null
     */
    public function popInterval(): ?array
    {
        return array_pop($this->priceStack);
    }

    public function hasIntervals(): bool
    {
        return $this->priceStack !== [];
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    public function resetPage(): void
    {
        $this->page = 1;
    }
}
