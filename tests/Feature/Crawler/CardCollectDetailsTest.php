<?php

declare(strict_types=1);

use App\Dto\Procurement\Acquisition\DetailFetched;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Acquisition\FetchDeferred;
use App\Dto\Procurement\Network\DecodedTextBody;
use App\Dto\Procurement\Network\ResponseEnvelope;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Platform\Completeness;
use Illuminate\Support\Facades\DB;
use Tests\Support\Platforms\Contract\ContractRuntime;
use Tests\Support\Platforms\Contract\RecordingOutbound;
use Tests\Support\Platforms\Eis\EisDetailFixtures as D;

test('typed EIS card plans pin the requested identity in every tab URL without database writes', function (): void {
    $outbound = D::outbound(D::bodies());
    $result = D::source($outbound)->fetch(D::request(), ContractRuntime::context());
    expect($result)->toBeInstanceOf(DetailFetched::class)->and($outbound->plans)->toHaveCount(4);
    foreach ($outbound->plans as $plan) {
        parse_str(parse_url($plan->url->reveal(), PHP_URL_QUERY), $query);
        expect($query['regNumber'])->toBe('0338200009125000001');
    }
    expect($result->snapshot->documents)->toHaveCount(6)->and($result->snapshot->parties)->toHaveCount(2)
        ->and($result->snapshot->events)->not->toBe([])->and($result->snapshot->results)->not->toBeNull()
        ->and(DB::table('prc_procurements')->count())->toBe(0);
    foreach ($result->snapshot->parts as $part) {
        if ($part->name === 'results') {
            // The historical failed excerpt has no participant table or explicit failed protocol type.
            expect($part->completeness)->toBe(Completeness::Partial);
        } else {
            expect($part->completeness->value)->toBeIn(['complete', 'complete_empty', 'not_applicable'], $part->name);
        }
    }
});

test('typed EIS optional supplier results use explicit NotApplicable and no physical send', function (): void {
    $bodies = D::bodies();
    unset($bodies['supplier-results']);
    $outbound = D::outbound($bodies);
    $result = D::source($outbound, results: false)->fetch(D::request(), ContractRuntime::context());
    expect($result)->toBeInstanceOf(DetailFetched::class)->and($outbound->plans)->toHaveCount(3)
        ->and($result->snapshot->results)->toBeNull();
    $part = array_values(array_filter($result->snapshot->parts, fn ($p) => $p->name === 'results'))[0];
    expect($part->completeness)->toBe(Completeness::NotApplicable);
    foreach ($outbound->plans as $plan) {
        expect($plan->url->reveal())->not->toContain('supplier-results');
    }
});

test('typed EIS status refresh reads only common info and leaves the supplier tab untouched', function (): void {
    $outbound = D::outbound([D::bodies()['common-info']]);
    $result = D::source($outbound)->refresh(D::request(), ContractRuntime::context());
    expect($result)->toBeInstanceOf(DetailFetched::class)->and($outbound->plans)->toHaveCount(1)
        ->and($outbound->plans[0]->url->reveal())->toContain('common-info.html?regNumber=0338200009125000001')
        ->and($result->snapshot->results)->toBeNull();
});

test('typed EIS unknown procedure fails before acquiring a network request', function (): void {
    $request = D::request();
    $bad = new DetailRequest($request->registry, $request->identity,
        new CursorEnvelope('eis.card', 1, 'unknown'));
    $outbound = new RecordingOutbound;
    expect(fn () => D::source($outbound)->fetch($bad, ContractRuntime::context()))->toThrow(InvalidArgumentException::class)
        ->and($outbound->plans)->toBe([]);
});

test('supplier-results с отклонёнными или несоответствующими заявками по ч. 1 ст. 52 44-ФЗ завершается как CompleteEmpty', function (): void {
    foreach ([
        'По результатам рассмотрения заявок на участие в закупке все поданные заявки отклонены (п. 4 ч. 1 ст. 52 Закона № 44-ФЗ)',
        'По окончании срока подачи заявок подана только одна заявка на участие в закупке. Заявка не соответствует требованиям. (п. 1 и п. 4 ч. 1 ст. 52 Закона № 44-ФЗ)',
    ] as $reason) {
        $bodies = D::bodies();
        $bodies['supplier-results'] = str_replace('По окончании срока подачи заявок подана только одна заявка на участие в закупке (п. 1 ч. 1 ст. 52 Закона № 44-ФЗ). Заявка соответствует требованиям', $reason, $bodies['supplier-results']);
        $result = D::source(D::outbound($bodies))->fetch(D::request(), ContractRuntime::context());
        $part = array_values(array_filter($result->snapshot->parts, fn ($p) => $p->name === 'results'))[0];
        expect($result)->toBeInstanceOf(DetailFetched::class)->and($result->snapshot->complete())->toBeTrue()
            ->and($part->completeness)->toBe(Completeness::CompleteEmpty);
    }
});

test('common-info закупки по ч. 12 ст. 93 44-ФЗ без блока Объект закупки в шапке берёт наименование из позиции КТРУ', function (): void {
    $bodies = D::bodies();
    $bodies['common-info'] = str_replace('<div class="cardMainInfo__purchaseObject">Поставка оргтехники</div>', '', $bodies['common-info']);
    $result = D::source(D::outbound($bodies))->fetch(D::request(), ContractRuntime::context());
    $part = array_values(array_filter($result->snapshot->parts, fn ($p) => $p->name === 'attributes'))[0];
    expect($result)->toBeInstanceOf(DetailFetched::class)->and($result->snapshot->title)->not->toBe('')
        ->and($part->completeness)->toBe(Completeness::Complete);
});

test('карточка ЕИС при HTTP 403 или 429 от WAF возвращает отложенное получение RateLimit без поломки прогресса', function (): void {
    foreach ([403, 429] as $status) {
        $response = new ResponseEnvelope($status, ['content-type' => ['text/html']], new DecodedTextBody('Forbidden', 'UTF-8'), 9, 9);
        $outbound = new RecordingOutbound([new TransportSucceeded($response)]);
        $result = D::source($outbound)->fetchPart(D::request(), 'common-info', ContractRuntime::context());
        expect($result)->toBeInstanceOf(FetchDeferred::class)->and($result->reason)->toBe(NetworkDeferralReason::RateLimit);
    }
});
