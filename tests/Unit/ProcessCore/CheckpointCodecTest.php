<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\NoProviderRecovery;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkCheckpointEnvelope;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\ProviderOperationPurpose;
use App\Enums\Procurement\Process\ProviderRecoveryMode;
use App\Enums\Procurement\Process\StageCode;
use App\Services\Procurement\Process\ProcessPayloadCodec;
use Tests\Support\ProcessCore\ContractFixtures as F;
use Tests\Support\ProcessCore\FixtureStageCheckpoint;
use Tests\Support\ProcessCore\FixtureStagePayloadCodec;

test('manifest закрепляет вручную подготовленный recovery corpus и его schema version', function (): void {
    $manifest = json_decode(file_get_contents(base_path('tests/Fixtures/ProcessCore/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
    $fixture = collect($manifest['contract_fixtures'] ?? [])->sole('id', 'process-contracts-v1');

    expect($fixture['path'])->toBe('tests/Support/ProcessCore/contracts-v1.json')
        ->and($fixture['sha256'])->toBe(hash_file('sha256', base_path($fixture['path'])))
        ->and($fixture['expected']['canonical_dto'])->toBe(WorkCheckpointEnvelope::class)
        ->and($fixture['expected']['schema_version'])->toBe(1)
        ->and($fixture['scenario_ids'])->toBe(['T15', 'T22'])
        ->and($fixture['coverage'])->toBe('pure_codec_only');
});

test('versioned recovery corpus проверяет вручную заданный DTO и отказ error variant', function (): void {
    $corpus = json_decode(file_get_contents(base_path('tests/Support/ProcessCore/contracts-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    $payload = new VersionedPayload(json_encode($corpus['valid_checkpoint'], JSON_THROW_ON_ERROR), $corpus['schema_version']);
    expect(F::codec()->decodeCheckpoint($payload))->toEqual(new WorkCheckpointEnvelope(new FixtureStageCheckpoint(7), F::recovery()));
    $invalid = new VersionedPayload(json_encode($corpus['invalid_checkpoint'], JSON_THROW_ON_ERROR), $corpus['schema_version']);
    expect(fn () => F::codec()->decodeCheckpoint($invalid))->toThrow(InvalidArgumentException::class);
});

pest()->group('p11');

test('checkpoint roundtrip сохраняет pinned receipt и directive при progress restart и consumption', function (): void {
    $codec = F::codec();
    $plan = F::recovery();
    $envelope = new WorkCheckpointEnvelope(new FixtureStageCheckpoint(7), $plan);
    $decoded = $codec->decodeCheckpoint($codec->encodeCheckpoint($envelope));
    expect($decoded)->toEqual($envelope);
    $advanced = $decoded->withProgress(new FixtureStageCheckpoint(8));
    expect($advanced->providerRecoveryPlan)->toBe($decoded->providerRecoveryPlan)
        ->and($codec->decodeCheckpoint($codec->encodeCheckpoint($advanced)))->toEqual($advanced)
        ->and($advanced->providerRecoveryPlan->receipt->sha256->hex)->toBe(str_repeat('a', 64))
        ->and($advanced->providerRecoveryPlan->requestEpoch)->toBe(4);
    $consumed = $advanced->withoutProviderRecovery();
    expect($consumed->stageProgress)->toBe($advanced->stageProgress)
        ->and($consumed->providerRecoveryPlan)->toBeInstanceOf(NoProviderRecovery::class)
        ->and($advanced->providerRecoveryPlan)->toBeInstanceOf(ProviderRecoveryPlan::class);
    expect(fn () => $advanced->withProgress(new FixtureStageCheckpoint(1, StageCode::Download)))->toThrow(InvalidArgumentException::class);
});

test('recovery fixture roundtrip покрывает каждый purpose и mode без provider runtime', function (): void {
    $plans = [new NoProviderRecovery, F::recovery()];
    foreach ([ProviderOperationPurpose::Cache, ProviderOperationPurpose::File, ProviderOperationPurpose::Lookup] as $purpose) {
        $plans[] = F::recovery(ProviderRecoveryMode::ResumeProviderAsset, $purpose);
    }
    foreach ([ProviderRecoveryMode::KnownRetryEligible, ProviderRecoveryMode::BlockedUnknown] as $mode) {
        foreach (ProviderOperationPurpose::cases() as $purpose) {
            $plans[] = F::recovery($mode, $purpose, false);
            $plans[] = F::recovery($mode, $purpose, true);
        }
    }
    foreach ($plans as $plan) {
        $checkpoint = new WorkCheckpointEnvelope(new FixtureStageCheckpoint(0), $plan);
        expect(F::codec()->decodeCheckpoint(F::codec()->encodeCheckpoint($checkpoint)))->toEqual($checkpoint);
    }
});

test('recovery codec отклоняет mode purpose receipt version linkage и unknown variant', function (string $field, mixed $value): void {
    $codec = F::codec();
    $payload = json_decode($codec->encodeCheckpoint(new WorkCheckpointEnvelope(new FixtureStageCheckpoint(1), F::recovery()))->json, true, flags: JSON_THROW_ON_ERROR);
    $payload['provider_recovery_plan'][$field] = $value;
    expect(fn () => $codec->decodeCheckpoint(new VersionedPayload(json_encode($payload, JSON_THROW_ON_ERROR), 1)))->toThrow(InvalidArgumentException::class);
})->with([
    'no receipt' => ['receipt', null],
    'cache receipt cannot become generation' => ['purpose', 'cache'],
    'file receipt cannot become generation' => ['purpose', 'file'],
    'lookup receipt cannot become generation' => ['purpose', 'lookup'],
    'error is not generation resume' => ['mode', 'verified_error'],
    'generation cannot become asset resume' => ['mode', 'resume_provider_asset'],
    'unknown plan' => ['variant', 'latest_receipt'],
    'unknown mode' => ['mode', 'retry'],
    'unknown purpose' => ['purpose', 'arbitrary'],
    'unknown version' => ['plan_version', 2],
    'coerced version' => ['plan_version', '1'],
    'zero epoch' => ['request_epoch', 0],
    'malformed physical id' => ['physical_call_id', 'bad'],
    'null logical id' => ['logical_request_id', null],
    'wrong hash' => ['expected_input_hash', 'bad'],
    'unknown field' => ['raw', 'must not be in checkpoint'],
]);

test('checkpoint codec не принимает неизвестные stage codecs и внутренние schema versions', function (): void {
    $envelope = new WorkCheckpointEnvelope(new FixtureStageCheckpoint(1), new NoProviderRecovery);
    expect(fn () => (new ProcessPayloadCodec)->encodeCheckpoint($envelope))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ProcessPayloadCodec([new FixtureStagePayloadCodec, new FixtureStagePayloadCodec]))->toThrow(InvalidArgumentException::class);
    $base = json_decode(F::codec()->encodeCheckpoint($envelope)->json, true, flags: JSON_THROW_ON_ERROR);
    foreach ([['schema_version', 2], ['stage', 'download'], ['payload', ['position' => -1]], ['payload', ['position' => 0, 'hidden' => true]], ['payload', ['position' => '1']]] as [$field, $value]) {
        $payload = $base;
        $payload['stage_progress'][$field] = $value;
        expect(fn () => F::codec()->decodeCheckpoint(new VersionedPayload(json_encode($payload, JSON_THROW_ON_ERROR), 1)))->toThrow(InvalidArgumentException::class);
    }
    foreach ([['variant' => 'none', 'receipt' => null], ['variant' => 'unknown']] as $recovery) {
        $payload = $base;
        $payload['provider_recovery_plan'] = $recovery;
        expect(fn () => F::codec()->decodeCheckpoint(new VersionedPayload(json_encode($payload, JSON_THROW_ON_ERROR), 1)))->toThrow(InvalidArgumentException::class);
    }
});

test('lease хранит immutable checkpoint и отвергает recovery чужого input', function (): void {
    $checkpoint = new WorkCheckpointEnvelope(new FixtureStageCheckpoint(1), F::recovery());
    $lease = new ExecutionLease(new WorkId(F::id()), F::id(4), 1, F::id(5), new DateTimeImmutable('2026-10-24T12:00:00Z'), 1, F::hash(), 1, $checkpoint, StageCode::Listing, 'fixture-listing/1');
    expect($lease->checkpoint)->toBe($checkpoint);
    expect(fn () => new ExecutionLease($lease->workId, $lease->attemptId, 2, $lease->token, $lease->leaseUntil, 1, new ContentHash(str_repeat('b', 64)), 1, $checkpoint, StageCode::Listing, 'fixture-listing/1'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ExecutionLease($lease->workId, $lease->attemptId, 2, $lease->token, $lease->leaseUntil, 1, F::hash(), 1, $checkpoint, StageCode::Detail, 'fixture-detail/1'))->toThrow(InvalidArgumentException::class);
});
