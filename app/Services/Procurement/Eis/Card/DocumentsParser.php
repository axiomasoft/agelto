<?php

declare(strict_types=1);

namespace App\Services\Procurement\Eis\Card;

use App\Dto\Procurement\Details\DocumentData;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Разбор вкладки `documents` карточки закупки ЕИС (`findings/P4-card-layout.md` §2, живая проба
 * P4.13) в список {@see DocumentData}. Только HTML → DTO, без записи в БД (это `DocumentIndexer`)
 * и без скачивания файла (это `DocumentDownloader`). Ссылка на файл — АБСОЛЮТНАЯ
 * (`https://zakupki.gov.ru/44fz/filestore/...`), резолвится как есть. Строка без файла-ссылки
 * (брак вёрстки/ещё не опубликован) — пропускается, без неё нет `external_id` для дедупа. Вкладка
 * не несёт колонки с типом документа — `kind` легитимно `null` для всех строк.
 */
final readonly class DocumentsParser
{
    /**
     * @return list<DocumentData>
     */
    public function parse(string $html): array
    {
        try {
            $crawler = new Crawler($html);
            $block = $crawler->filter('div.blockFilesTabDocs');

            if ($block->count() === 0) {
                $this->warnIfFilestoreLinksSuppressed($crawler, recognized: 0);

                return [];
            }

            $documents = [];

            $block->filter('div.attachment')->each(function (Crawler $row) use (&$documents): void {
                $document = $this->parseRow($row);

                if ($document !== null) {
                    $documents[] = $document;
                }
            });

            $this->warnIfFilestoreLinksSuppressed($crawler, recognized: count($documents));

            return $documents;
        } catch (\Throwable $exception) {
            Log::warning('Разбор вкладки documents провалился целиком, документы пропущены', [
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function parseRow(Crawler $row): ?DocumentData
    {
        $link = $row->filter('span.section__value > a');

        if ($link->count() === 0) {
            Log::warning('Строка вкладки documents без файла-ссылки пропущена', []);

            return null;
        }

        $href = $link->attr('href');
        $externalId = $this->externalIdFromHref($href);

        if ($externalId === null || $href === null) {
            Log::warning('Строка вкладки documents без распознаваемого uid пропущена', ['href' => $href]);

            return null;
        }

        $title = $link->attr('title');
        $title = $title !== null && trim($title) !== '' ? trim($title) : trim($link->text());

        return new DocumentData(
            title: $title,
            kind: null,
            sourceUrl: $this->absoluteUrl($href),
            externalId: $externalId,
        );
    }

    /**
     * Тихий отказ (research/10-p4-remediation.md §3.6): страница несёт ссылки `filestore`, а
     * распознано 0 документов — признак разошедшейся вёрстки, обязан быть виден в логе.
     */
    private function warnIfFilestoreLinksSuppressed(Crawler $crawler, int $recognized): void
    {
        if ($recognized > 0) {
            return;
        }

        $filestoreLinks = $crawler->filter('a[href*="filestore"]')->count();

        if ($filestoreLinks > 0) {
            Log::warning('На вкладке documents есть ссылки filestore, но не распознано ни одного документа', [
                'filestore_links' => $filestoreLinks,
            ]);
        }
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

        $base = rtrim((string) config('crawler.eis.base_url'), '/');

        return $base.'/'.ltrim($href, '/');
    }
}
