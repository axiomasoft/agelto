<?php

declare(strict_types=1);

namespace App\Services\Crawler\Eis\Card;

use App\Dto\Crawler\Details\DocumentData;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Разбор вкладки `documents` карточки закупки ЕИС (`research/eis-card-recon.md` §2,
 * `research/eis-csv-recon.md` «Бонус: страница вложений») в список
 * {@see DocumentData}. Только HTML → DTO, без записи в БД (это `DocumentIndexer`) и без
 * скачивания файла (это `DocumentDownloader`). Ссылка на файл — относительный `filestore/...`
 * путь, резолвится в абсолютный URL через `crawler.eis.base_url`. Строка без файла-ссылки
 * (брак вёрстки/ещё не опубликован) — пропускается, без неё нет `external_id` для дедупа.
 */
final readonly class DocumentsParser
{
    /**
     * @return list<DocumentData>
     */
    public function parse(string $html): array
    {
        try {
            $table = (new Crawler($html))->filter('div.documents-list table.documents-table');

            if ($table->count() === 0) {
                return [];
            }

            $documents = [];

            $table->filter('tbody tr')->each(function (Crawler $tr) use (&$documents): void {
                $document = $this->parseRow($tr);

                if ($document !== null) {
                    $documents[] = $document;
                }
            });

            return $documents;
        } catch (\Throwable) {
            return [];
        }
    }

    private function parseRow(Crawler $tr): ?DocumentData
    {
        $cells = $tr->filter('td');

        if ($cells->count() < 3) {
            return null;
        }

        $link = $cells->eq(2)->filter('a');

        if ($link->count() === 0) {
            Log::warning('Строка вкладки documents без файла-ссылки пропущена', ['row' => trim($cells->eq(0)->text())]);

            return null;
        }

        $href = $link->attr('href');
        $externalId = $this->externalIdFromHref($href);

        if ($externalId === null || $href === null) {
            Log::warning('Строка вкладки documents без распознаваемого uid пропущена', ['href' => $href]);

            return null;
        }

        $kind = trim($cells->eq(0)->text());

        return new DocumentData(
            title: trim($link->text()),
            kind: $kind !== '' ? $kind : null,
            sourceUrl: $this->absoluteUrl($href),
            externalId: $externalId,
        );
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
