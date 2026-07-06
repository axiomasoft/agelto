<?php

declare(strict_types=1);

use App\Dto\Procurement\Details\CommonInfoData;
use App\Models\Procurement\EisDetails;
use App\Models\Procurement\Financing;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\PurchaseItem;
use App\Models\Reference\Okpd2;
use App\Services\Procurement\Detail\CommonInfoProcessor;
use App\Services\Procurement\Eis\Card\CommonInfoParser;

function commonInfoFixtureData(): CommonInfoData
{
    $html = file_get_contents(base_path('tests/Fixtures/Crawler/Card/common-info.html'));

    return app(CommonInfoParser::class)->parse($html);
}

test('process создаёт eis_details/financing/items из разобранных данных вкладки', function () {
    $procurement = Procurement::factory()->create();

    app(CommonInfoProcessor::class)->process($procurement, commonInfoFixtureData());

    expect(EisDetails::query()->where('procurement_id', $procurement->id)->count())->toBe(1)
        ->and(Financing::query()->where('procurement_id', $procurement->id)->count())->toBe(2)
        ->and(PurchaseItem::query()->where('procurement_id', $procurement->id)->count())->toBe(1);

    $details = EisDetails::query()->where('procurement_id', $procurement->id)->firstOrFail();

    expect($details->ikz_year)->toBe(26)
        ->and($details->etp_name)->toBe('АО «Единая электронная торговая площадка»')
        ->and($details->etp_url)->toBe('https://www.roseltorg.ru/')
        ->and($details->contact_person['name'])->toBe('Иванов Иван Иванович')
        ->and($details->contact_person['email'])->toBe('zakupki@example.gov.ru');
});

test('повторный process идемпотентен — строки не задваиваются', function () {
    $procurement = Procurement::factory()->create();
    $data = commonInfoFixtureData();

    app(CommonInfoProcessor::class)->process($procurement, $data);
    app(CommonInfoProcessor::class)->process($procurement, $data);

    expect(EisDetails::query()->where('procurement_id', $procurement->id)->count())->toBe(1)
        ->and(Financing::query()->where('procurement_id', $procurement->id)->count())->toBe(2)
        ->and(PurchaseItem::query()->where('procurement_id', $procurement->id)->count())->toBe(1);
});

test('process резолвит okpd2_ref_id, когда код есть в справочнике ref_okpd2', function () {
    $procurement = Procurement::factory()->create();
    $okpd2 = Okpd2::factory()->create(['code' => '41.20.40']);

    app(CommonInfoProcessor::class)->process($procurement, commonInfoFixtureData());

    $item = PurchaseItem::query()->where('procurement_id', $procurement->id)->firstOrFail();

    expect($item->classifier_code)->toBe('41.20.40')
        ->and($item->okpd2_ref_id)->toBe($okpd2->id);
});

test('process сохраняет сырой classifier_code без резолва, если кода нет в справочниках', function () {
    $procurement = Procurement::factory()->create();

    app(CommonInfoProcessor::class)->process($procurement, commonInfoFixtureData());

    $item = PurchaseItem::query()->where('procurement_id', $procurement->id)->firstOrFail();

    expect($item->classifier_code)->toBe('41.20.40')
        ->and($item->okpd2_ref_id)->toBeNull()
        ->and($item->ktru_ref_id)->toBeNull();
});
