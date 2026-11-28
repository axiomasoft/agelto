<?php

declare(strict_types=1);

use App\Dto\Procurement\Parsing\Extraction\DriftSignal;
use App\Dto\Procurement\Parsing\Primitives\ColumnMappingRule;
use App\Dto\Procurement\Parsing\Primitives\EventClassificationRule;
use App\Dto\Procurement\Parsing\Primitives\RegexPattern;
use App\Dto\Procurement\Parsing\Primitives\SelectorChain;
use App\Dto\Procurement\Parsing\Rules\CardDomRule;
use App\Dto\Procurement\Parsing\Rules\CardRoutesRule;
use App\Dto\Procurement\Parsing\Rules\CsvDialectRule;
use App\Dto\Procurement\Parsing\Rules\DocumentsRule;
use App\Dto\Procurement\Parsing\Rules\EventJournalRule;
use App\Dto\Procurement\Parsing\Rules\JsonDetailRule;
use App\Dto\Procurement\Parsing\Rules\JsonListingRule;
use App\Dto\Procurement\Parsing\Rules\ListingColumnsRule;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\ProcurementEventType;
use App\Services\Procurement\Platform\Contracts\CardUrlRouter;
use App\Services\Procurement\Platform\Contracts\DocumentListExtractor;
use App\Services\Procurement\Platform\Contracts\DomExtractor;
use App\Services\Procurement\Platform\Contracts\DriftCollector;
use App\Services\Procurement\Platform\Contracts\EventTableExtractor;
use App\Services\Procurement\Platform\Contracts\JsonPayloadExtractor;
use App\Services\Procurement\Platform\Contracts\TabularReader;
use App\Services\Procurement\Platform\Contracts\TabularSummaryMapper;

test('универсальный движок читает CSV и маппит колонки по правилам', function (): void {
    $reader = app(TabularReader::class);
    $mapper = app(TabularSummaryMapper::class);

    $csv = "\xEF\xBB\xBFНомер;Наименование;Лишняя\n\"№ 0123\";\"Ремонт крыши\";\"x\"\n\"№ 0123\";\"Дубликат\";\"y\"\n\"\";\"Пустой номер\";\"z\"\n";
    $table = $reader->read($csv, new CsvDialectRule);

    $mapped = $mapper->map(
        $table['headers'],
        $table['rows'],
        new ListingColumnsRule(
            columns: [
                new ColumnMappingRule(
                    targetField: 'external_id',
                    headerAliases: ['Номер'],
                    required: true,
                    valueRegex: new RegexPattern('/(\d+)/u'),
                ),
                new ColumnMappingRule(
                    targetField: 'title',
                    headerAliases: ['Наименование'],
                    required: true,
                ),
            ],
            deduplicateByField: 'external_id',
        ),
    );

    expect($mapped['items'])->toHaveCount(1)
        ->and($mapped['items'][0])->toBe(['external_id' => '0123', 'title' => 'Ремонт крыши'])
        ->and($mapped['summary']->totalRows)->toBe(3)
        ->and($mapped['summary']->mappedRows)->toBe(1)
        ->and($mapped['summary']->skippedRows)->toBe(2)
        ->and($mapped['summary']->unmatchedHeaders)->toBe(['Лишняя']);
});

test('универсальный DOM-движок извлекает пары, документы, события и маршруты карточки', function (): void {
    $dom = app(DomExtractor::class);
    $docsExtractor = app(DocumentListExtractor::class);
    $eventsExtractor = app(EventTableExtractor::class);
    $router = app(CardUrlRouter::class);

    $html = <<<'HTML'
    <html><body>
      <div class="field-row"><span class="label">Способ</span><span class="val">Аукцион</span></div>
      <div class="docs">
        <div class="doc-item"><a href="/files/spec.pdf" class="doc-link">Техническое задание</a><span class="doc-size">120 KB</span></div>
        <div class="doc-item"><a href="/files/skip.exe" class="doc-link">Исполняемый</a></div>
      </div>
      <table class="journal">
        <tr class="j-row"><td class="j-date">10.10.2026</td><td class="j-desc">Размещено извещение о проведении закупки</td></tr>
      </table>
    </body></html>
    HTML;

    $xpath = $dom->loadHtml($html);
    $pairs = $dom->extractKeyValuePairs($xpath, new CardDomRule(
        fieldRowsSelectors: SelectorChain::fromArray(['.field-row']),
        labelSelectors: SelectorChain::fromArray(['.label']),
        valueSelectors: SelectorChain::fromArray(['.val']),
    ));
    expect($pairs)->toBe(['Способ' => 'Аукцион']);

    $docs = $docsExtractor->extract($xpath, new DocumentsRule(
        containerSelectors: SelectorChain::fromArray(['.docs']),
        itemSelectors: SelectorChain::fromArray(['.doc-item']),
        titleSelectors: SelectorChain::fromArray(['.doc-link']),
        urlSelectors: SelectorChain::fromArray(['.doc-link']),
        sizeSelectors: SelectorChain::fromArray(['.doc-size']),
        allowedExtensions: ['pdf'],
    ));
    expect($docs)->toHaveCount(1)
        ->and($docs[0]['title'])->toBe('Техническое задание')
        ->and($docs[0]['url'])->toBe('/files/spec.pdf');

    $events = $eventsExtractor->extract($xpath, new EventJournalRule(
        rowSelectors: SelectorChain::fromArray(['.j-row']),
        dateSelectors: SelectorChain::fromArray(['.j-date']),
        descriptionSelectors: SelectorChain::fromArray(['.j-desc']),
        classifications: [
            new EventClassificationRule(ProcurementEventType::RevisionPublished, ['извещение'], null, 10),
        ],
    ));
    expect($events)->toHaveCount(1)
        ->and($events[0]['event_type'])->toBe(ProcurementEventType::RevisionPublished->value);

    $routes = new CardRoutesRule(
        commonInfoPath: '/card/{regNumber}/common',
        documentsPath: 'https://example.test/docs?regNumber={regNumber}',
    );
    expect($router->buildUrl($routes, 'common-info', 'https://example.test', '999'))->toBe('https://example.test/card/999/common')
        ->and($router->buildUrl($routes, 'documents', 'https://example.test', '999'))->toBe('https://example.test/docs?regNumber=999')
        ->and($router->availableTabs($routes))->toBe(['common-info', 'documents']);
});

test('JSON-экстрактор и коллектор дрейфа работают по контрактам', function (): void {
    $json = app(JsonPayloadExtractor::class);
    $drift = app(DriftCollector::class);

    $listing = $json->extractListing(
        ['data' => ['items' => [['id' => 'A1', 'meta' => ['sum' => 100]]], 'total' => 1]],
        new JsonListingRule('data.items', ['external_id' => 'id', 'price' => 'meta.sum'], 'data.total'),
    );
    expect($listing['items'])->toBe([['external_id' => 'A1', 'price' => 100]])
        ->and($listing['total'])->toBe(1);

    $detail = $json->extractDetail(
        ['card' => ['number' => 'A1', 'files' => [['name' => 'doc.pdf']]]],
        new JsonDetailRule('card', ['number' => 'number'], 'files', ['title' => 'name']),
    );
    expect($detail['fields'])->toBe(['number' => 'A1'])
        ->and($detail['documents'])->toBe([['title' => 'doc.pdf']]);

    $drift->clear();
    $drift->record(new DriftSignal('selector_miss', StageCode::Detail, '.title', null, null, 'critical'));
    expect($drift->hasCritical())->toBeTrue()
        ->and($drift->signals())->toHaveCount(1);
});

test('табличный маппер не добавляет отсутствующие необязательные колонки в запись', function (): void {
    $mapper = app(TabularSummaryMapper::class);

    $mapped = $mapper->map(
        ['Номер', 'Наименование'],
        [['0123', 'Ремонт крыши']],
        new ListingColumnsRule(
            columns: [
                new ColumnMappingRule(
                    targetField: 'external_id',
                    headerAliases: ['Номер'],
                    required: true,
                ),
                new ColumnMappingRule(
                    targetField: 'title',
                    headerAliases: ['Наименование'],
                    required: true,
                ),
                new ColumnMappingRule(
                    targetField: 'customer_name',
                    headerAliases: ['Заказчик'],
                    required: false,
                ),
            ],
        ),
    );

    expect($mapped['items'])->toHaveCount(1)
        ->and($mapped['items'][0])->toBe(['external_id' => '0123', 'title' => 'Ремонт крыши'])
        ->and(array_key_exists('customer_name', $mapped['items'][0]))->toBeFalse();
});
