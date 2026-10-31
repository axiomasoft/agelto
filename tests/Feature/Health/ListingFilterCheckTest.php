<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisListingFilter;
use App\Dto\Procurement\Acquisition\ListingRequest;
use App\Dto\Procurement\Platform\DiagnosticInput;
use App\Dto\Procurement\Platform\ProbePlan;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Services\Platforms\Eis\Diagnostics\ListingFilterCheck;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Procurement\Health\HealthBaselineReader;
use Tests\Support\ProcessCore\ListingFixtures as F;

function filterEvidence(array $pages, int $requests = 2, ?NetworkDeferralReason $unavailable = null): DiagnosticInput
{
    return new DiagnosticInput(app(HealthBaselineReader::class)->read(), $pages, $requests, 1,
        new DateTimeImmutable('2026-10-31T00:00:00Z'), new DateTimeImmutable('2026-10-31T23:59:59Z'), $unavailable);
}

test('проверка подтверждает действие ОКПД2 на двух проверенных ответах', function (): void {
    $result = (new ListingFilterCheck)->run(filterEvidence([F::page([F::summary('A')]), F::page([F::summary('B')])]));
    expect($result->verdict)->toBe(PlatformCheckVerdict::Ok)->and($result->requestsUsed)->toBe(2)
        ->and($result->measured['overlap'])->toBe(0)->and($result->measured['overlap_share'])->toBe(0.0);
});

test('проверка считает совпавшие множества реестровых номеров поломкой фильтра', function (): void {
    $page = F::page([F::summary('A')]);
    $result = (new ListingFilterCheck)->run(filterEvidence([$page, $page]));
    expect($result->verdict)->toBe(PlatformCheckVerdict::Broken)->and($result->measured['overlap_share'])->toBe(1.0);
});

test('проверка считает пустой непроверенный ответ недостатком данных', function (): void {
    $result = (new ListingFilterCheck)->run(filterEvidence([F::page([F::summary('A')])]));
    expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData)->and($result->requestsUsed)->toBe(2);
});

test('проверка называет исчерпание бюджета недостатком данных', function (): void {
    $result = (new ListingFilterCheck)->run(filterEvidence([], 0, NetworkDeferralReason::RateLimit));
    expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData)->and($result->requestsUsed)->toBe(0)
        ->and($result->message)->toContain('rate_limit');
});

test('проверка называет недоступность прокси недостатком данных', function (): void {
    $result = (new ListingFilterCheck)->run(filterEvidence([], 0, NetworkDeferralReason::NoRoute));
    expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData)->and($result->requestsUsed)->toBe(0)
        ->and($result->message)->toContain('no_route');
});

test('проверка называет сетевой отказ недостатком данных', function (): void {
    $result = (new ListingFilterCheck)->run(filterEvidence([], 1, NetworkDeferralReason::DependencyUnavailable));
    expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData)->and($result->requestsUsed)->toBe(1);
});

test('проверка не допускает третьего logical request и не исполняет сеть при оценке', function (): void {
    $filter = new EisListingFilter(['123']);
    $plan = (new EisListingPartitioner)->plan($filter, '2026-01-01', '2026-01-01');
    $request = new ListingRequest('fz44', $filter, $plan->windows[0]->cursor);
    expect(fn () => new ProbePlan([]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ProbePlan([null]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ProbePlan([$request, $request, $request]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new DiagnosticInput(app(HealthBaselineReader::class)->read(), [null], 0, 1,
            new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-02')))->toThrow(InvalidArgumentException::class);
});
