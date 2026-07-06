<?php

declare(strict_types=1);

use App\Enums\Procurement\AwardStatus;
use App\Enums\Procurement\BidStatus;
use App\Enums\Procurement\ProtocolType;
use App\Services\Crawler\Eis\Card\SupplierResultsParser;

function supplierResultsFixtureHtml(): string
{
    return file_get_contents(base_path('tests/Fixtures/Crawler/Card/supplier-results.html'));
}

test('parse разбирает протокол, заявки и победителя из вкладки supplier-results', function () {
    $data = app(SupplierResultsParser::class)->parse(supplierResultsFixtureHtml());

    expect($data->protocol)->not->toBeNull()
        ->and($data->protocol->type)->toBe(ProtocolType::Summary->value)
        ->and($data->protocol->sourceNumber)->toBe('ИЗК1')
        ->and($data->protocol->signedAt->toDateString())->toBe('2026-07-15');

    expect($data->bids)->toHaveCount(3);

    expect($data->bids[0]->organization?->fullName)->toBe('ООО «Стройремонт»')
        ->and($data->bids[0]->organization?->inn)->toBe('7701234567')
        ->and($data->bids[0]->organization?->ogrn)->toBe('1027700123456')
        ->and($data->bids[0]->price)->toBe(1450000.0)
        ->and($data->bids[0]->status)->toBe(BidStatus::Winner);

    // Участники без ИНН/ОГРН в источнике не резолвятся в OrganizationData вовсе (не только
    // без ИНН) — иначе OrganizationResolver рискует склеить разных участников в одну
    // org_organizations (см. BidData/SupplierResultsProcessor, ревью P3.7).
    expect($data->bids[1]->organization)->toBeNull()
        ->and($data->bids[1]->price)->toBe(1480000.0)
        ->and($data->bids[1]->status)->toBe(BidStatus::Admitted);

    expect($data->bids[2]->organization)->toBeNull()
        ->and($data->bids[2]->price)->toBe(1490000.0)
        ->and($data->bids[2]->status)->toBe(BidStatus::Admitted);

    expect($data->award)->not->toBeNull()
        ->and($data->award->winner->inn)->toBe('7701234567')
        ->and($data->award->finalPrice)->toBe(1450000.0)
        ->and($data->award->status)->toBe(AwardStatus::Awarded);
});

test('parse возвращает пустой результат при отсутствии блока supplier-results', function () {
    $html = preg_replace('#<div class="supplier-results">.*?</div>\s*</body>#s', '</body>', supplierResultsFixtureHtml());

    $data = app(SupplierResultsParser::class)->parse($html);

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
