<?php

declare(strict_types=1);

use App\Dto\Procurement\Acquisition\DetailFetched;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Platform\CursorEnvelope;
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
