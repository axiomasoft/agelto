<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Card;

use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Details\DocumentData;
use App\Dto\Procurement\Details\DocumentsSnapshot;
use App\Dto\Procurement\Network\SafeUrl;
use App\Enums\Procurement\DetailSnapshotStatus;
use App\Services\Procurement\Platform\Contracts\DocumentUrlPolicy;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Разбор вкладки `documents` карточки закупки ЕИС в {@see DocumentsSnapshot}.
 * Только HTML → DTO, без записи в БД (это `DocumentIndexer`)
 * и без скачивания файла (это `DocumentDownloader`). Ссылка на файл — АБСОЛЮТНАЯ
 * (`https://zakupki.gov.ru/44fz/filestore/...`), резолвится как есть. Строка без файла-ссылки
 * (брак вёрстки/ещё не опубликован) — пропускается, без неё нет `external_id` для дедупа. Вкладка
 * не несёт колонки с типом документа — `kind` легитимно `null` для всех строк.
 */
final readonly class DocumentsParser
{
    public function __construct(private DocumentUrlPolicy $targetValidator, private EisPolicy $policy) {}

    public function parse(string $html, bool $strict = false): DocumentsSnapshot
    {
        try {
            $crawler = new Crawler($html);
            $block = $crawler->filter('div.blockFilesTabDocs');

            if ($block->count() === 0) {
                CardDomHelper::logWarning('Вкладка documents не содержит контейнер вложений; snapshot неполный', [
                    'filestore_links' => $crawler->filter('a[href*="filestore"]')->count(),
                ]);

                return $this->snapshot(status: DetailSnapshotStatus::Incomplete);
            }

            $documents = [];
            $incomplete = false;

            $block->filter('div.attachment')->each(function (Crawler $row) use (&$documents, &$incomplete): void {
                $document = $this->parseRow($row);

                if ($document === null) {
                    $incomplete = true;

                    return;
                }

                $documents[] = $document;
            });

            if ($this->hasSuppressedFilestoreLinks(crawler: $crawler, recognized: count($documents))) {
                $incomplete = true;
            }

            return $this->snapshot(
                status: $incomplete ? DetailSnapshotStatus::Incomplete : DetailSnapshotStatus::Complete,
                documents: $documents,
            );
        } catch (\Throwable $exception) {
            if ($strict) {
                throw $exception;
            }
            CardDomHelper::logWarning('Разбор вкладки documents провалился целиком; snapshot failed', [
                'exception' => $exception->getMessage(),
            ]);

            return $this->snapshot(status: DetailSnapshotStatus::Failed);
        }
    }

    private function parseRow(Crawler $row): ?DocumentData
    {
        $link = $row->filter('span.section__value > a');

        if ($link->count() === 0) {
            CardDomHelper::logWarning('Строка вкладки documents без файла-ссылки пропущена', []);

            return null;
        }

        $href = $link->attr('href');
        $externalId = $this->externalIdFromHref($href);

        if ($externalId === null || $href === null) {
            CardDomHelper::logWarning('Строка вкладки documents без распознаваемого uid пропущена', ['href' => $href]);

            return null;
        }

        $title = $link->attr('title');
        $title = $title !== null && trim($title) !== '' ? trim($title) : trim($link->text());

        $sourceUrl = $this->absoluteUrl($href);
        $this->targetValidator->assertDocumentUrl(url: $sourceUrl);

        return new DocumentData(
            title: $title,
            kind: null,
            sourceUrl: $sourceUrl,
            externalId: $externalId,
        );
    }

    /**
     * Тихий отказ (research/10-p4-remediation.md §3.6): страница несёт ссылки `filestore`, а
     * распознано 0 документов — признак разошедшейся вёрстки, обязан быть виден в логе.
     */
    private function hasSuppressedFilestoreLinks(Crawler $crawler, int $recognized): bool
    {
        $filestoreLinks = $crawler->filter('a[href*="filestore"]')->count();

        if ($filestoreLinks !== $recognized) {
            CardDomHelper::logWarning('На вкладке documents есть нераспознанные ссылки filestore; snapshot неполный', [
                'filestore_links' => $filestoreLinks,
                'recognized' => $recognized,
            ]);
        }

        return $filestoreLinks !== $recognized;
    }

    /** @param list<DocumentData> $documents */
    private function snapshot(DetailSnapshotStatus $status, array $documents = []): DocumentsSnapshot
    {
        return new DocumentsSnapshot(status: $status, documents: $documents);
    }

    private function externalIdFromHref(?string $href): ?string
    {
        if ($href === null) {
            return null;
        }

        $query = parse_url($href, PHP_URL_QUERY);

        if (! is_string($query)) {
            return null;
        }

        parse_str($query, $params);

        return isset($params['uid']) && is_string($params['uid']) && $params['uid'] !== '' ? $params['uid'] : null;
    }

    private function absoluteUrl(string $href): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        $base = (new SafeUrl($this->policy->endpoints->get('card')->url))->origin->value();

        return $base.'/'.ltrim($href, '/');
    }
}
