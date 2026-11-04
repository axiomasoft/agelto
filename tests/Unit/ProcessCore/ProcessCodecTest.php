<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\DownloadInput;
use App\Dto\Procurement\Process\ExtractionWindowInput;
use App\Dto\Procurement\Process\ListingInput;
use App\Dto\Procurement\Process\MissingParts;
use App\Dto\Procurement\Process\PrepareInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\PublicationInput;
use App\Dto\Procurement\Process\RetryAt;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\StageFailedItem;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\StagePartial;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Dto\Procurement\Process\StatusRefreshInput;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkIdentity;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\DeferralReason;
use App\Enums\Procurement\Process\ItemFailureReason;
use App\Enums\Procurement\Process\StageCode;
use App\Services\Procurement\Process\ProcessPayloadCodec;
use Tests\Support\ProcessCore\ContractFixtures as F;
use Tests\Support\ProcessCore\FixtureStageCheckpoint;
use Tests\Support\ProcessCore\FixtureStageResult;

test('codec сохраняет восемь closed inputs и canonical identity независимо от порядка JSON полей', function (): void {
    $id = F::id();
    $hash = F::hash();
    $cases = [
        [new ListingInput($id, $hash, $hash, 'adapter-1', 1), '{"stage":"listing","crawl_block_id":"01900000-0000-7000-8000-000000000001","filter_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","access_policy_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","adapter_version":"adapter-1","cursor_version":1}'],
        [new DetailInput(12, $id, 1, $hash, $hash, 'adapter-1'), '{"stage":"detail","procurement_id":12,"intent_id":"01900000-0000-7000-8000-000000000001","expected_head_generation":1,"semantic_policy_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","access_policy_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","adapter_version":"adapter-1"}'],
        [new StatusRefreshInput(12, 2, $hash, 'adapter-1'), '{"stage":"status_refresh","procurement_id":12,"cycle_seq":2,"status_policy_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","adapter_version":"adapter-1"}'],
        [new DownloadInput(15, $id, 1, $hash), '{"stage":"download","document_id":15,"acquisition_intent_id":"01900000-0000-7000-8000-000000000001","expected_document_head_generation":1,"access_policy_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}'],
        [new PrepareInput(16, $hash), '{"stage":"prepare","document_version_id":16,"preparation_recipe_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}'],
        [new ScreeningInput(16, $hash, $hash), '{"stage":"screening","document_version_id":16,"representation_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","screening_recipe_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}'],
        [new ExtractionWindowInput($id, 'window:0001', $hash), '{"stage":"extraction","analysis_run_id":"01900000-0000-7000-8000-000000000001","window_key":"window:0001","recipe_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}'],
        [new PublicationInput($id, $hash, 2), '{"stage":"publication","analysis_run_id":"01900000-0000-7000-8000-000000000001","recipe_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","result_generation":2}'],
    ];
    $codec = new ProcessPayloadCodec;
    $keys = [];
    foreach ($cases as [$input, $golden]) {
        $golden = str_replace('HASH', str_repeat('a', 64), $golden);
        $payload = $codec->encodeInput($input);
        expect($payload->schemaVersion)->toBe(1)->and($payload->json)->toBe($golden)
            ->and($codec->decodeInput($payload))->toEqual($input);
        $reordered = json_encode(array_reverse(json_decode($golden, true, flags: JSON_THROW_ON_ERROR), true), JSON_THROW_ON_ERROR);
        $decoded = $codec->decodeInput(new VersionedPayload($reordered, 1));
        $key = (new WorkIdentity($input))->key()->hex;
        expect((new WorkIdentity($decoded))->key()->hex)->toBe($key)
            ->and($key)->toBe(hash('sha256', 'work-identity:v1:'.$golden));
        $keys[] = $key;
    }
    expect(array_unique($keys))->toHaveCount(8);
    expect((new WorkIdentity(new StatusRefreshInput(12, 3, $hash, 'adapter-1')))->key()->hex)->not->toBe($keys[2]);
});

test('codec roundtrip сохраняет все пять outcome variants и их явную семантику', function (): void {
    $codec = F::codec();
    $outcomes = [
        new StageSucceeded(new FixtureStageResult(F::artifact())),
        new StagePartial(new FixtureStageCheckpoint(4), new MissingParts(['next_page'])),
        new StageDeferred(DeferralReason::NoRoute, new RetryAt(new DateTimeImmutable('2026-10-24T15:00:00.123456+03:00'))),
        new StageBlocked(BlockedReason::HandlerFaultLimit),
        new StageFailedItem(ItemFailureReason::ContractRejected, F::artifact()),
    ];
    foreach ($outcomes as $outcome) {
        $encoded = $codec->encodeOutcome($outcome);
        expect($codec->decodeOutcome($encoded))->toEqual($outcome);
    }
    expect($codec->encodeOutcome($outcomes[2])->json)->toBe('{"variant":"deferred","reason":"no_route","retry_at":"2026-10-24T12:00:00.123456Z"}');
    foreach (DeferralReason::cases() as $reason) {
        $outcome = new StageDeferred($reason, $outcomes[2]->retryAt);
        expect($codec->decodeOutcome($codec->encodeOutcome($outcome)))->toEqual($outcome);
    }
    foreach (BlockedReason::cases() as $reason) {
        $outcome = new StageBlocked($reason);
        expect($codec->decodeOutcome($codec->encodeOutcome($outcome)))->toEqual($outcome);
    }
});

test('codec отклоняет absent required payload и unsupported schema versions', function (): void {
    $codec = F::codec();
    foreach ([new VersionedPayload(null, null), new VersionedPayload('{}', 2)] as $payload) {
        expect(fn () => $codec->decodeInput($payload))->toThrow(InvalidArgumentException::class);
        expect(fn () => $codec->decodeOutcome($payload))->toThrow(InvalidArgumentException::class);
    }
    expect($codec->decodeCheckpoint(new VersionedPayload(null, null)))->toBeNull()
        ->and($codec->encodeCheckpoint(null))->toEqual(new VersionedPayload(null, null));
    expect(fn () => new VersionedPayload('{}', null))->toThrow(InvalidArgumentException::class);
    expect(fn () => new VersionedPayload(null, 1))->toThrow(InvalidArgumentException::class);
    expect(fn () => new VersionedPayload('{}', 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $codec->decodeCheckpoint(new VersionedPayload('{}', 2)))->toThrow(InvalidArgumentException::class);
});

test('codec отвергает malformed JSON unknown поля variants и scalar coercion', function (string $json): void {
    expect(fn () => F::codec()->decodeInput(new VersionedPayload($json, 1)))->toThrow(InvalidArgumentException::class);
})->with([
    'malformed' => '{', 'null' => 'null', 'array' => '[]', 'scalar' => 'true',
    'unknown stage' => '{"stage":"status"}',
    'missing field' => '{"stage":"prepare","document_version_id":16}',
    'unknown field' => '{"stage":"prepare","document_version_id":16,"preparation_recipe_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","raw_html":"secret"}',
    'null field' => '{"stage":"prepare","document_version_id":null,"preparation_recipe_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}',
    'string id' => '{"stage":"prepare","document_version_id":"16","preparation_recipe_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}',
    'float id' => '{"stage":"prepare","document_version_id":16.0,"preparation_recipe_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}',
    'zero id' => '{"stage":"prepare","document_version_id":0,"preparation_recipe_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}',
    'bad hash' => '{"stage":"prepare","document_version_id":16,"preparation_recipe_hash":"bad"}',
]);

test('closed outcomes отвергают неизвестные handler variants и несогласованные поля', function (string $json): void {
    expect(fn () => F::codec()->decodeOutcome(new VersionedPayload($json, 1)))->toThrow(InvalidArgumentException::class);
})->with([
    '{"variant":"cancelled"}', '{"variant":"superseded"}', '{"variant":"success"}',
    '{"variant":"blocked","reason":"provider_result_unknown","due_at":"2099-01-01"}',
    '{"variant":"deferred","reason":"no_usable_route","retry_at":"2026-10-24T12:00:00.000000Z"}',
    '{"variant":"deferred","reason":"no_route","retry_at":"2026-02-30T12:00:00.000000Z"}',
    '{"variant":"deferred","reason":"no_route","retry_at":null}',
    '{"variant":"blocked","reason":"not_registered"}',
    '{"variant":"partial","checkpoint":null,"missing":[]}',
    '{"variant":"succeeded","result":true}',
]);

test('encoding неизвестного PHP variant не становится success или произвольным payload', function (): void {
    $outcome = new class implements StageOutcome {};
    $input = new class implements WorkInput
    {
        public function stage(): StageCode
        {
            return StageCode::Listing;
        }
    };
    expect(fn () => F::codec()->encodeOutcome($outcome))->toThrow(InvalidArgumentException::class);
    expect(fn () => F::codec()->encodeInput($input))->toThrow(InvalidArgumentException::class);
});

test('value constraints отвергают malformed UUID hash и пустой progress intent', function (): void {
    expect(fn () => new ProcessUuid('not-a-uuid'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ContentHash(str_repeat('A', 64)))->toThrow(InvalidArgumentException::class);
    expect(fn () => new MissingParts([]))->toThrow(InvalidArgumentException::class);
    expect(fn () => new MissingParts(['page', 'page']))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ListingInput(F::id(), F::hash(), F::hash(), '', 1))->toThrow(InvalidArgumentException::class);
    expect(fn () => new PrepareInput(-1, F::hash()))->toThrow(InvalidArgumentException::class);
});
