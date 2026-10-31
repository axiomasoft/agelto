<?php

declare(strict_types=1);

use App\Dto\Procurement\Acquisition\DetailFetched;
use App\Enums\Procurement\Platform\Completeness;
use App\Exceptions\Procurement\Health\HealthBaselineUnavailableException;
use App\Repositories\Procurement\Crawler\DetailSnapshotRepository;
use App\Services\Procurement\Acquisition\DetailArtifacts;
use App\Services\Procurement\Acquisition\DetailHandler;
use App\Services\Procurement\Health\HealthBaselineReader;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\DetailFixtures as F;
use Tests\Support\ProcessCore\DetailFixtureSource;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;

function detailOutcomeAttempt(array $f, array $responses): void
{
    $lease = FinalizeFixtures::running($f['work']);
    $handler = new DetailHandler(app(DetailSnapshotRepository::class), new DetailFixtureSource($responses, ['attributes']),
        app(DetailArtifacts::class), new FetchOutcomeBridge);
    app(WorkFinalizer::class)->finish($lease,
        $handler->execute($f['input'], app(ExecutionContextFactory::class)->open($lease)));
}

beforeEach(fn () => F::bind());

test('Process detail retains incomplete attempt evidence and publishes only the later coherent outcome', function (): void {
    $f = F::work();
    detailOutcomeAttempt($f, [new DetailFetched(F::snapshot($f['request'], 'attributes', Completeness::Partial))]);
    expect(ExecutionFixtures::work($f['work'])->state)->toBe('ready')
        ->and(DB::table('prc_detail_snapshots')->where('part_key', 'like', '%:published')->count())->toBe(0);
    $complete = new DetailFetched(F::snapshot($f['request'], 'attributes'));
    detailOutcomeAttempt($f, [$complete, $complete]);
    expect(ExecutionFixtures::work($f['work'])->state)->toBe('succeeded')
        ->and(ExecutionFixtures::work($f['work'])->item_failures)->toBe(0)
        ->and(DB::table('prc_process_attempts')->where('work_id', $f['work']->value->value)->whereNotNull('finished_at')->count())->toBe(2)
        ->and(DB::table('prc_detail_snapshots')->where('part_key', 'like', '%:published')->count())->toBe(1);
});

test('missing diagnostic baseline fails closed without consuming detail budget and corrected configuration recovers', function (): void {
    $f = F::work();
    $configured = config('crawler.health.baseline_path');
    config(['crawler.health.baseline_path' => 'tests/Fixtures/Health/missing-baseline.json']);
    expect(fn () => app(HealthBaselineReader::class)->read())->toThrow(HealthBaselineUnavailableException::class)
        ->and(ExecutionFixtures::work($f['work'])->state)->toBe('ready')
        ->and(ExecutionFixtures::work($f['work'])->item_failures)->toBe(0)
        ->and(DB::table('prc_process_attempts')->count())->toBe(0)
        ->and(DB::table('prc_detail_snapshots')->where('part_key', 'like', '%:published')->count())->toBe(0);
    config(['crawler.health.baseline_path' => $configured]);
    expect(app(HealthBaselineReader::class)->read()->sha256)->toHaveLength(64);
    $complete = new DetailFetched(F::snapshot($f['request'], 'attributes'));
    detailOutcomeAttempt($f, [$complete, $complete]);
    expect(ExecutionFixtures::work($f['work'])->state)->toBe('succeeded')
        ->and(ExecutionFixtures::work($f['work'])->item_failures)->toBe(0);
});
