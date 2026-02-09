<?php

declare(strict_types=1);

namespace Platform\Repositories\Crawling;

use Illuminate\Database\Eloquent\Collection;
use Platform\Dto\Crawling\LinkDto;
use Platform\Enums\Crawling\CrawlingStatus;
use Platform\Models\Crawling\Crawler;
use Platform\Models\Crawling\Link;

final class CrawlingRepository
{
    /**
     * Основной метод сохранения результата краулинга (Stage 1).
     * Сбрасывает статус в PENDING и обнуляет ошибки при обновлении данных.
     */
    public function storeOrUpdateLink(Crawler $crawler, LinkDto $dto): Link
    {
        return Link::query()->updateOrCreate(
            [
                'crawler_id' => $crawler->id,
                'reg_number' => $dto->regNumber,
            ],
            [
                'detail_url' => $dto->detailUrl,
                'published_at' => $dto->publishedAt,
                'submission_end_at' => $dto->submissionEndAt,
                'status' => CrawlingStatus::PENDING,
                'attempts' => 0,
                'last_error' => null,
                'next_retry_at' => null,
            ]
        );
    }

    /**
     * Получение ссылок для обработки парсером документов (Stage 2).
     * Сортировка по дате публикации (старые — первыми).
     * * @return Collection<int, Link>
     */
    public function getPendingLinksForCrawler(Crawler $crawler, int $limit = 100): Collection
    {
        return Link::query()
            ->where('crawler_id', $crawler->id)
            ->where('status', CrawlingStatus::PENDING)
            ->orderBy('published_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Поиск профиля краулера.
     */
    public function findCrawler(int $id): ?Crawler
    {
        return Crawler::query()->find($id);
    }

    /**
     * Поиск профиля с предзагрузкой платформы (Eis, B2B и т.д.).
     */
    public function findCrawlerWithPlatform(int $id): ?Crawler
    {
        return Crawler::query()->with('platform')->find($id);
    }

    /**
     * Обновление статуса ссылки (например, при начале парсинга документов).
     */
    public function updateStatus(Link $link, CrawlingStatus $status): void
    {
        $link->update(['status' => $status]);
    }

    /**
     * Фиксация ошибки обработки ссылки с логикой экспоненциальной задержки.
     */
    public function markAsFailed(Link $link, string $errorMessage): void
    {
        $link->increment('attempts');

        // Чем больше попыток, тем дольше ждем (2, 4, 8, 16... минут)
        $delayMinutes = (int) pow(2, $link->attempts);

        $link->update([
            'status' => CrawlingStatus::FAILED,
            'last_error' => $errorMessage,
            'next_retry_at' => now()->addMinutes($delayMinutes),
        ]);
    }
}
