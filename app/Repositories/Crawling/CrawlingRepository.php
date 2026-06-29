<?php

declare(strict_types=1);

namespace App\Repositories\Crawling;

use App\Dto\Attachments\AttachmentDto;
use App\Dto\Crawling\LinkDto;
use App\Enums\Crawling\CrawlingStatus;
use App\Models\Crawler\Crawler;
use App\Models\Crawler\Link;
use App\Models\Crawler\LinkAttachment;
use Illuminate\Database\Eloquent\Collection;
use Spatie\LaravelData\DataCollection;

final class CrawlingRepository
{
    /**
     * Основной метод сохранения результата краулинга (Stage 1).
     * Статус/прогресс ретраев сбрасываются только при реально изменившихся данных извещения.
     * Сохраняет вложения (attachments) для ссылки.
     */
    public function storeOrUpdateLink(Crawler $crawler, LinkDto $dto): Link
    {
        $existing = $this->findLink($crawler->platform->id, $dto->regNumber);

        // «Данные переехали» = ссылка новая или извещение обновилось на площадке.
        $isStale = $existing === null
            || $existing->platform_updated_at?->timestamp !== $dto->updatedAt?->timestamp;

        $values = [
            'crawler_id' => $crawler->id,
            'detail_url' => $dto->detailUrl,
            'subject' => $dto->subject,
            'okved' => $dto->okved,
            'okved_additional' => $dto->okvedAdditional,
            'published_at' => $dto->publishedAt,
            'platform_updated_at' => $dto->updatedAt,
            'submission_end_at' => $dto->submissionEndAt,
        ];

        // Сбрасываем статус и прогресс только если данные изменились — иначе повторный обход
        // затирал бы накопленные attempts/last_error и зацикливал обработку ссылки (H2).
        if ($isStale) {
            $values['status'] = CrawlingStatus::PENDING;
            $values['attempts'] = 0;
            $values['last_error'] = null;
            $values['last_error_at'] = null;
            $values['next_retry_at'] = null;
            $values['failed_permanently'] = false;
        }

        $link = Link::query()->updateOrCreate(
            attributes: [
                'platform_id' => $crawler->platform->id,
                'reg_number' => $dto->regNumber,
            ],
            values: $values,
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
            ->orderBy(column: 'published_at', direction: 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * PENDING-ссылки всех краулеров с предзагруженными вложениями без анализа —
     * источник конвейера Стадия 1 → 2 (discover & dispatch).
     *
     * @return Collection<int, Link>
     */
    public function getPendingLinks(int $limit = 100): Collection
    {
        return Link::query()
            ->where('status', CrawlingStatus::PENDING)
            ->with(['attachments' => fn ($query) => $query->whereDoesntHave('documentAnalyses')])
            ->orderBy(column: 'published_at', direction: 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Поиск ссылки по платформе и рег. номеру (для проверки актуальности перед подгрузкой документов).
     */
    public function findLink(int $platformId, string $regNumber): ?Link
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
    public function markFailed(Link $link, string $errorMessage): void
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
                attributes: [
                    'link_id' => $link->id,
                    'external_id' => $dto->externalId,
                ],
                values: [
                    'url' => $dto->url,
                    'name' => $dto->name,
                    'extension' => $extension,
                ]
            );
        }

        // Удаляем только пропавшие вложения без анализа — чтобы не осиротить запущенные DocumentAnalysis.
        $link->attachments()
            ->whereNotIn('url', $urls)
            ->whereDoesntHave('documentAnalyses')
            ->delete();
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
