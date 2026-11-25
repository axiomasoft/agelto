<?php

declare(strict_types=1);

use App\Enums\Procurement\AwardStatus;
use App\Enums\Procurement\BidStatus;
use App\Enums\Procurement\ProtocolType;
use App\Services\Platforms\Eis\Card\SupplierResultsParser;
use Symfony\Component\DomCrawler\Crawler;

function supplierResultsSuccessFixtureHtml(): string
{
    return file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/supplier-results-success.html'));
}

function supplierResultsFailedFixtureHtml(): string
{
    return file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/supplier-results.html'));
}

function supplierResultsSuccessFixtureWithout(string $selector): string
{
    $crawler = new Crawler(supplierResultsSuccessFixtureHtml());

    $crawler->filter($selector)->each(static function (Crawler $node): void {
        $domNode = $node->getNode(0);
        $domNode?->parentNode?->removeChild($domNode);
    });

    return $crawler->html();
}

function supplierResultsSuccessFixtureWithoutProtocol(): string
{
    $crawler = new Crawler(supplierResultsSuccessFixtureHtml());
    $protocolTitle = $crawler
        ->filter('span.section__title')
        ->reduce(static fn (Crawler $title): bool => str_starts_with(
            trim($title->text()),
            'Наименование протокола определения поставщика',
        ));
    $section = $protocolTitle->ancestors()->first()->getNode(0);
    $section?->parentNode?->removeChild($section);

    return $crawler->html();
}

test('parse разбирает протокол из живой success-фикстуры', function () {
    $data = app(SupplierResultsParser::class)->parse(supplierResultsSuccessFixtureHtml());

    expect($data->protocol)->not->toBeNull()
        ->and($data->protocol->type)->toBe(ProtocolType::Summary->value)
        ->and($data->protocol->sourceNumber)->toBe('ИЭОК')
        ->and($data->protocol->signedAt?->toDateString())->toBe('2026-08-07')
        ->and($data->protocol->publishedAt?->toDateString())->toBe('2026-08-07');
});

test('parse разбирает анонимную заявку победителя и цену из живой success-фикстуры', function () {
    $data = app(SupplierResultsParser::class)->parse(supplierResultsSuccessFixtureHtml());

    expect($data->bids)->toHaveCount(1)
        ->and($data->bids[0]->organization)->toBeNull()
        ->and($data->bids[0]->bidNumber)->toBe('2')
        ->and($data->bids[0]->participantOrdinal)->toBe(1)
        ->and($data->bids[0]->price)->toBe('10000000.00')
        ->and($data->bids[0]->status)->toBe(BidStatus::Winner);
});

test('parse создаёт award без выдуманной организации по подтверждённому рангу победителя', function () {
    $data = app(SupplierResultsParser::class)->parse(supplierResultsSuccessFixtureHtml());

    expect($data->award)->not->toBeNull()
        ->and($data->award->winner)->toBeNull()
        ->and($data->award->finalPrice)->toBe('10000000.00')
        ->and($data->award->status)->toBe(AwardStatus::Awarded)
        ->and($data->award->awardedAt?->toDateString())->toBe('2026-08-07');
});

test('parse сохраняет протокол несостоявшейся закупки, но не создаёт заявки и award', function () {
    $data = app(SupplierResultsParser::class)->parse(supplierResultsFailedFixtureHtml());

    expect($data->protocol)->not->toBeNull()
        ->and($data->protocol->sourceNumber)->toBe('ИЭА')
        ->and($data->bids)->toBe([])
        ->and($data->award)->toBeNull();
});

test('parse не создаёт заявки и award после удаления таблицы участников из живой фикстуры', function () {
    $html = supplierResultsSuccessFixtureWithout('div[id^="supplier-def-result-participant-table-"]');
    $data = app(SupplierResultsParser::class)->parse($html);

    expect($data->protocol)->not->toBeNull()
        ->and($data->bids)->toBe([])
        ->and($data->award)->toBeNull();
});

test('parse использует дату формирования результата после удаления протокола из живой фикстуры', function () {
    $data = app(SupplierResultsParser::class)->parse(supplierResultsSuccessFixtureWithoutProtocol());

    expect($data->protocol)->toBeNull()
        ->and($data->award)->not->toBeNull()
        ->and($data->award->awardedAt?->toDateString())->toBe('2026-08-07');
});

test('parse возвращает пустой результат при отсутствии распознаваемой вкладки', function () {
    $data = app(SupplierResultsParser::class)->parse('<html><body>пусто</body></html>');

    expect($data->protocol)->toBeNull()
        ->and($data->bids)->toBe([])
        ->and($data->award)->toBeNull();
});

test('parse возвращает пустой результат для пустой строки', function () {
    $data = app(SupplierResultsParser::class)->parse('');

    expect($data->protocol)->toBeNull()
        ->and($data->bids)->toBe([])
        ->and($data->award)->toBeNull();
});
