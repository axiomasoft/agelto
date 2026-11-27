<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\CancelWork;
use App\Dto\Procurement\Process\Outcomes\StageBlocked;
use App\Dto\Procurement\Process\Outcomes\StageDeferred;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\TerminalProof;
use App\Enums\Procurement\Process\Governance\ControlMode;
use App\Exceptions\Procurement\Process\ExecutionInterrupted;
use App\Models\Procurement\Crawler\RawArtifact;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use Illuminate\Support\Facades\DB;
use Proxyma\Infrastructure\Database\PackageSchema;
use Tests\Support\Platforms\Eis\EisListingFactsFixtures as C;
use Tests\Support\ProcessCore\ExecutionFixtures as E;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\Http\AcquisitionWireFixture;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\RawEvidenceFixtures;

beforeEach(function (): void {
    PermitFixtures::clean();
    $this->rawEvidence = new RawEvidenceFixtures;
    $this->rawBefore = RawArtifact::query()->pluck('id')->all();
    $this->platformBefore = Platform::query()->where('code', 'eis')->value('id');
    $this->registryBefore = Registry::query()->where('platform_id', $this->platformBefore)->where('code', 'fz44')->value('id');
    $this->wire = new WireFixture;
});

afterEach(function (): void {
    $this->wire->stop();
    PermitFixtures::clean();
    $this->rawEvidence->clean();
    if (isset($this->fixture)) {
        $platform = $this->fixture->registry->platform;
        if ($this->registryBefore === null) {
            $this->fixture->registry->delete();
        }
        if ($this->platformBefore === null) {
            $platform->delete();
        }
    }
});

test('Process EIS invalid required identity and dates never prove an empty source day', function (array $change): void {
    $page = C::parse(C::body([C::row('1', $change)]));
    expect($page->assessment->completeness)->toBe(Completeness::Partial)->and($page->terminalProof)->toBe(TerminalProof::None)
        ->and($page->summaries->items)->toBe([])->and(DB::table('prc_procurements')->count())->toBe(0);
})->with(['invalid identity' => [['Реестровый номер закупки' => '№']], 'invalid publish date' => [['Дата размещения' => '31.02.2025']],
    'missing update date' => [['Дата обновления' => '']]]);

test('Process EIS semantic empty requires valid CSV content type as well as complete headers', function (): void {
    $body = C::body([]);
    expect(C::parse($body)->assessment->completeness)->toBe(Completeness::CompleteEmpty)
        ->and(C::parse($body, type: 'text/html')->assessment->completeness)->toBe(Completeness::Partial)
        ->and(C::parse($body, type: 'text/html')->terminalProof)->toBe(TerminalProof::None);
});

test('actual acquisition checks current Process pause cancellation and ownership before sending any bytes', function (string $stop): void {
    $this->fixture = new AcquisitionWireFixture($this->wire);
    $this->wire->respond('/csv', C::body([]), headers: ['Content-Type' => 'text/csv; charset=UTF-8']);
    try {
        $outcome = $this->fixture->fetchWork($this->fixture->listingRequest(), function ($context) use ($stop): void {
            $work = $context->lease()->workId;
            if ($stop === 'pause') {
                E::setMode('acquisition', ControlMode::Paused);
            } elseif ($stop === 'cancel') {
                app(CancelWork::class)->execute($work, 'cancel before send');
            } else {
                FinalizeFixtures::expire($work);
                FinalizeFixtures::revoke($work);
            }
        });
        expect($outcome instanceof StageBlocked || $outcome instanceof StageDeferred)->toBeTrue();
    } catch (ExecutionInterrupted) {
        // The same current-owner check may interrupt before the network gate is reached.
    }
    expect($this->wire->receipts())->toBe([])->and(DB::table('prc_request_attempts')->count())->toBe(0)
        ->and(DB::table('prc_procurements')->count())->toBe(0)
        ->and(DB::table('prc_crawl_blocks')->whereNotNull('terminal_proof')->count())->toBe(0);
})->with(['pause' => ['pause'], 'cancel' => ['cancel'], 'stale owner' => ['stale']]);

test('actual acquisition exhausted network retries cannot reuse a previous HTTP error as final input', function (): void {
    $this->fixture = new AcquisitionWireFixture($this->wire, '/unavailable-then-drop');
    $outcome = $this->fixture->fetchWork($this->fixture->listingRequest());
    $tables = PackageSchema::tables();
    expect($outcome)->toBeInstanceOf(StageDeferred::class)->and($this->wire->receipts())->toHaveCount(3)
        ->and(DB::table($tables['permits'])->whereNotNull('lease_released_at')->count())->toBe(3)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0)
        ->and(DB::table('prc_procurements')->count())->toBe(0)
        ->and(RawArtifact::query()->whereNotIn('id', $this->rawBefore)->count())->toBe(0)
        ->and(DB::table('prc_process_work')->value('item_failures'))->toBe(0);
    $attempts = DB::table($tables['attempts'])->orderBy('authorized_at')->get();
    $owners = DB::table($tables['operations'])->whereIn('id', $attempts->pluck('operation_id'))->pluck('owner_id');
    expect($attempts->pluck('physical_attempt_id')->unique())->toHaveCount(3)
        ->and($owners->filter())->toHaveCount(3)->and($owners->unique())->toHaveCount(1)
        ->and($attempts->pluck('status')->all())->toBe(['completed', 'unknown_after_authorization', 'unknown_after_authorization'])
        ->and($attempts->map(fn (object $attempt): mixed => json_decode($attempt->result_summary, true, flags: JSON_THROW_ON_ERROR)['status'])->all())->toBe([503, null, null]);
    foreach ($attempts as $attempt) {
        $released = DB::table($tables['permits'])->where('id', $attempt->permit_id)->value('lease_released_at');
        expect(new DateTimeImmutable($released))->toBeGreaterThanOrEqual(new DateTimeImmutable($attempt->authorized_at));
    }
});
