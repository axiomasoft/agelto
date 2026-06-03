<?php

declare(strict_types=1);

namespace App\Repositories\Crawling;

use Illuminate\Database\Eloquent\Collection;
use App\Dto\Attachments\AttachmentDto;
use App\Dto\Crawling\LinkDto;
use App\Enums\Crawling\CrawlingStatus;
use App\Models\Crawler\Crawler;
use App\Models\Crawler\Link;
use App\Models\Crawler\LinkAttachment;
use Spatie\LaravelData\DataCollection;

final class CrawlingRepository
{
    /**
     * Основной метод сохранения результата краулинга (Stage 1).
     * Сбрасывает статус в PENDING и обнуляет ошибки при обновлении данных.
     * Сохраняет вложения (attachments) для ссылки.
     */
    public function storeOrUpdateLink(Crawler $crawler, LinkDto $dto): Link
    {
        $link = Link::query()->updateOrCreate(
            [
                'platform_id' => $crawler->platform->id,
                'reg_number' => $dto->regNumber,
            ],
            [
                'detail_url' => $dto->detailUrl,
                'subject' => $dto->subject,
                'okved' => $dto->okved,
                'okved_additional' => $dto->okvedAdditional,
                'published_at' => $dto->publishedAt,
                'platform_updated_at' => $dto->updatedAt,
                'submission_end_at' => $dto->submissionEndAt,
                'status' => CrawlingStatus::PENDING,
                'attempts' => 0,
                'last_error' => null,
                'next_retry_at' => null,
            ]
        );

        if ($dto->attachments->count() > 0) {
            $this->syncAttachments($link, $dto->attachments);
        }

        return $link;
    }

    /**
     * Получение ссылок для обработки парсером документов (Stage 2).
     * Сортировка по дате публикации (старые — первыми).
     *
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
     * Поиск ссылки по платформе и рег. номеру (для проверки актуальности перед подгрузкой документов).
     */
    public function findLinkByPlatformAndRegNumber(int $platformId, string $regNumber): ?Link
    {
        return Link::query()
            ->where('platform_id', $platformId)
            ->where('reg_number', $regNumber)
            ->first();
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

    /**
     * Синхронизация вложений ссылки: создаёт или обновляет по url, удаляет отсутствующие.
     *
     * @param  DataCollection<int, AttachmentDto>  $attachments
     */
    private function syncAttachments(Link $link, DataCollection $attachments): void
    {
        $urls = [];

        foreach ($attachments as $dto) {
            $urls[] = $dto->url;
            $extension = $this->parseExtensionFromName($dto->name);
            LinkAttachment::query()->updateOrCreate(
                [
                    'link_id' => $link->id,
                    'external_id' => $dto->externalId,
                ],
                [
                    'url' => $dto->url,
                    'name' => $dto->name,
                    'extension' => $extension,
                ]
            );
        }

        $link->attachments()->whereNotIn('url', $urls)->delete();
    }

    /**
     * Извлекает расширение из имени файла. Не считается расширением, если длина > 12 символов.
     */
    private function parseExtensionFromName(string $name): ?string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);

        if ($extension === '' || mb_strlen($extension) > 12) {
            return null;
        }

        return $extension;
    }
}
