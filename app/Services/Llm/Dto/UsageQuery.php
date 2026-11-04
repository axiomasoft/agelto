<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

use Carbon\CarbonInterface;

/**
 * Запрос к источнику расхода: полуинтервал `[from, to)`
 * плюс НАБОР осей группировки. Пустой набор осей — законный запрос: одна строка-итог за окно.
 * Скользящее окно потребителя выражается как `[now()-N дн, now())`, календарный день — как
 * `[00:00 дня, 00:00 следующего)`; граница не принадлежит двум окнам сразу.
 */
final readonly class UsageQuery
{
    public function __construct(
        public CarbonInterface $from,
        public CarbonInterface $to,
        public bool $byDay = false,
        public bool $byProvider = false,
        public bool $byModel = false,
        public bool $byPurpose = false,
        public string $currency = 'USD',
    ) {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new \InvalidArgumentException('Invalid usage currency.');
        }
    }

    /**
     * Оси в порядке группировки — он же порядок сортировки ответа.
     *
     * @return list<string>
     */
    public function axes(): array
    {
        return array_values(array_filter([
            $this->byDay ? 'day' : null,
            $this->byProvider ? 'provider' : null,
            $this->byModel ? 'model' : null,
            $this->byPurpose ? 'purpose' : null,
        ]));
    }
}
