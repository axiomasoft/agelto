<?php

declare(strict_types=1);

use App\Dto\Procurement\Health\CheckResult;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Models\Procurement\Platform\Platform;
use App\Services\Procurement\Health\Checks\ListingShapeCheck;
use App\Services\Procurement\Health\HealthBaselineReader;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\LedgerFixtures;

// Reader fixtures model committed adapter evidence. Transport bytes/row assessments are
// covered by AcquisitionRuntimeTest; this check counts distinct validated inputs.
function listingAssessment(Platform $platform, string $verdict, ?string $input = null, bool $stale = false): void
{
    DB::table('prc_observations')->insert([
        'observation_id' => LedgerFixtures::uuid()->value, 'kind' => 'adapter_validation',
        'reason_code' => 'fixture.listing', 'input_hash' => DB::raw("decode('".hash('sha256', $input ?? LedgerFixtures::uuid()->value)."','hex')"),
        'generation' => 1, 'epochs' => '{}', 'epochs_schema_version' => 1, 'stale' => $stale,
        'details' => json_encode(['contract' => ['platform_code' => $platform->code, 'capability' => 'listing'], 'verdict' => $verdict], JSON_THROW_ON_ERROR),
        'details_schema_version' => 1, 'occurred_at' => now()->subSecond(),
    ]);
}

function listingShapeResult(Platform $platform): CheckResult
{
    return app(ListingShapeCheck::class)->run($platform, app(HealthBaselineReader::class)->read(), now()->subDay(), now());
}

test('проверка формы листинга подтверждает неизменившийся распарсенный блок', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);
    listingAssessment($platform, 'accepted');
    expect(listingShapeResult($platform)->verdict)->toBe(PlatformCheckVerdict::Ok);
});

test('проверка формы листинга считает полностью ошибочный блок broken среди healthy samples', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);
    listingAssessment($platform, 'accepted');
    listingAssessment($platform, 'contract_failure');
    expect(listingShapeResult($platform)->verdict)->toBe(PlatformCheckVerdict::Broken);
});

test('проверка формы листинга отражает частичные ошибки по доле строк', function (): void {
    // Row metrics no longer imply validation success; the canonical denominator is distinct inputs.
    $platform = Platform::factory()->create(['code' => 'eis']);
    foreach (range(1, 9) as $input) {
        listingAssessment($platform, 'accepted', (string) $input);
    }
    listingAssessment($platform, 'contract_failure');
    $result = listingShapeResult($platform);
    expect($result->verdict)->toBe(PlatformCheckVerdict::Degraded)
        ->and($result->measured['contract_failure_share'])->toBe(0.1)
        ->and($result->measured['source'])->toBe('adapter_validation');
});

test('проверка формы листинга считает исторические null-метрики неизвестными', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);
    listingAssessment($platform, 'partial');
    $result = listingShapeResult($platform);
    expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData)
        ->and($result->measured['incomplete_assessments'])->toBe(1);
});

test('проверка формы листинга не называет отсутствие блоков здоровьем', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);
    listingAssessment($platform, 'accepted', stale: true);
    expect(listingShapeResult($platform)->verdict)->toBe(PlatformCheckVerdict::NotEnoughData);
});
