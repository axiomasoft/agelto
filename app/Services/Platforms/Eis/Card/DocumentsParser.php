<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Card;

use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Details\DocumentData;
use App\Dto\Procurement\Details\DocumentsSnapshot;
use App\Dto\Procurement\Network\Http\SafeUrl;
use App\Dto\Procurement\Parsing\Extraction\DriftSignal;
use App\Dto\Procurement\Parsing\Primitives\SelectorChain;
use App\Dto\Procurement\Parsing\Rules\DetailRuleSetDto;
use App\Enums\Procurement\DetailSnapshotStatus;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Services\Platforms\Eis\EisDefaultParsingRules;
use App\Services\Procurement\Platform\Contracts\DocumentListExtractor;
use App\Services\Procurement\Platform\Contracts\DocumentUrlPolicy;
use App\Services\Procurement\Platform\Contracts\DriftCollector;
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
    public function __construct(
        private DocumentUrlPolicy $targetValidator,
        private EisPolicy $policy,
        private ?DetailRuleSetDto $rules = null,
        private ?DocumentListExtractor $extractor = null,
        private ?DriftCollector $drift = null,
    ) {}

    public function parse(string $html, bool $strict = false): DocumentsSnapshot
    {
        try {
            $rules = $this->rules ?? EisDefaultParsingRules::detail();
            $docRule = $rules->documents;
            $crawler = new Crawler($html);
            $block = $this->firstMatching($crawler, $docRule?->containerSelectors, 'div.blockFilesTabDocs');

            if ($block->count() === 0) {
                $fallbackSelector = $docRule?->containerSelectors?->selectors[0]->selector ?? 'div.blockFilesTabDocs';
                $this->drift?->record(new DriftSignal('documents_container_missing', StageCode::Detail, $fallbackSelector));
                CardDomHelper::logWarning('Вкладка documents не содержит контейнер вложений; snapshot неполный', [
                    'filestore_links' => $crawler->filter('a[href*="filestore"]')->count(),
                ]);

                return $this->snapshot(status: DetailSnapshotStatus::Incomplete);
            }

            $documents = [];
            $incomplete = false;

            if ($this->extractor !== null && $docRule !== null && $docRule->itemSelectors !== null) {
                $dom = new \DOMDocument;
                @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
                $extracted = $this->extractor->extract(new \DOMXPath($dom), $docRule);
                $expectedItemsCount = $this->firstMatching($block, $docRule->itemSelectors, 'div.attachment')->count();
                if (count($extracted) < $expectedItemsCount) {
                    $incomplete = true;
                }

                foreach ($extracted as $item) {
                    $href = $item['url'];
                    $externalId = $this->externalIdFromHref($href);
                    if ($externalId === null || $href === null) {
                        $incomplete = true;

                        continue;
                    }

                    $attrTitle = $this->linkTitleAttribute($block, $href);
                    $title = $attrTitle !== null && trim($attrTitle) !== ''
                        ? trim($attrTitle)
                        : ($item['title'] !== null && trim($item['title']) !== '' ? trim($item['title']) : '');
                    $sourceUrl = $this->absoluteUrl($href);
                    $this->targetValidator->assertDocumentUrl(url: $sourceUrl);

                    $documents[$externalId] ??= new DocumentData(
                        title: $title,
                        kind: null,
                        sourceUrl: $sourceUrl,
                        externalId: $externalId,
                    );
                }
            } else {
                $rows = $this->firstMatching($block, $docRule?->itemSelectors, 'div.attachment');
                $rows->each(function (Crawler $row) use (&$documents, &$incomplete, $docRule): void {
                    $document = $this->parseRow($row, $docRule?->urlSelectors, $docRule?->titleSelectors);

                    if ($document === null) {
                        $incomplete = true;

                        return;
                    }

                    $documents[$document->externalId ?? throw new \LogicException('Missing parsed document uid.')] ??= $document;
                });
            }

            if ($this->hasSuppressedFilestoreLinks(container: $block, recognized: array_keys($documents))) {
                $incomplete = true;
            }

            return $this->snapshot(
                status: $incomplete ? DetailSnapshotStatus::Incomplete : DetailSnapshotStatus::Complete,
                documents: array_values($documents),
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

    private function linkTitleAttribute(Crawler $block, string $href): ?string
    {
        foreach ($block->filter('a[href]') as $node) {
            $link = new Crawler($node);
            if ($link->attr('href') === $href) {
                return $link->attr('title');
            }
        }

        return null;
    }

    private function firstMatching(Crawler $crawler, ?SelectorChain $chain, string $defaultSelector): Crawler
    {
        if ($chain !== null) {
            foreach ($chain->selectors as $selector) {
                $matched = $crawler->filter($selector->selector);
                if ($matched->count() > 0) {
                    return $matched;
                }
            }

            return new Crawler;
        }

        return $crawler->filter($defaultSelector);
    }

    private function parseRow(Crawler $row, ?SelectorChain $urlSelectors = null, ?SelectorChain $titleSelectors = null): ?DocumentData
    {
        $link = $this->firstMatching($row, $urlSelectors, 'span.section__value > a');

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
        if ($title === null || trim($title) === '') {
            $titleNode = $titleSelectors !== null ? $this->firstMatching($row, $titleSelectors, 'span.section__value > a') : $link;
            $title = $titleNode->count() > 0 ? trim($titleNode->text()) : trim($link->text());
        } else {
            $title = trim($title);
        }

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
    /** @param list<array-key> $recognized */
    private function hasSuppressedFilestoreLinks(Crawler $container, array $recognized): bool
    {
        $uids = [];
        $unidentified = 0;
        foreach ($container->filter('a[href*="filestore"]') as $link) {
            $uid = $this->externalIdFromHref((new Crawler($link))->attr('href'));
            if ($uid === null) {
                $unidentified++;
            } else {
                $uids[$uid] = true;
            }
        }
        $incomplete = $unidentified > 0 || count($uids) !== count($recognized) || array_diff(array_keys($uids), $recognized) !== [];
        if ($incomplete) {
            CardDomHelper::logWarning('На вкладке documents есть нераспознанные ссылки filestore; snapshot неполный', [
                'filestore_uids' => count($uids),
                'unidentified' => $unidentified,
                'recognized' => count($recognized),
            ]);
        }

        return $incomplete;
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
