<?php

declare(strict_types=1);

use App\Actions\Procurement\Crawler\StoreRawFetchArtifactAction;
use App\Services\Procurement\Process\RawArtifactRetention;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Postgres\ConcurrentTransactions;

pest()->group('legacy-ownership-cutover');

test('retired raw prune and concurrent publication serialize across real PostgreSQL sessions', function (): void {
    DB::table('prc_raw_artifacts')->delete();
    $path = app(StoreRawFetchArtifactAction::class)->execute('local', 'raw/retired', ['id' => 317], 'bytes', 'txt');
    DB::table('prc_raw_artifacts')->update(['last_seen_at' => DB::raw("clock_timestamp() - interval '31 days'")]);
    $pathLiteral = var_export($path, true);
    RawArtifactRetention::withAddress('local', $path, function () use ($pathLiteral): void {
        $result = ConcurrentTransactions::execute("return ['locked' => \\Illuminate\\Support\\Facades\\DB::selectOne('SELECT pg_try_advisory_lock(317, hashtext(?)) AS locked', ['local:'.{$pathLiteral}])->locked];");
        expect($result['locked'])->toBeFalse();
    });
    $results = ConcurrentTransactions::concurrently([
        'producer' => <<<'PHP'
            $path = app(\App\Actions\Procurement\Crawler\StoreRawFetchArtifactAction::class)
                ->execute('local', 'raw/retired', ['id' => 317], 'bytes', 'txt');
            return ['path' => $path];
            PHP,
        'pruner' => <<<'PHP'
            return ['deleted' => app(\App\Services\Procurement\Process\RawArtifactRetention::class)->prune(1)];
            PHP,
    ]);
    expect($results['producer']['path'])->toBe($path)
        ->and(DB::table('prc_raw_artifacts')->where('path', $path)->whereRaw("last_seen_at > clock_timestamp() - interval '1 minute'")->exists())->toBeTrue()
        ->and(Storage::disk('local')->get($path))->toBe('bytes');
    DB::table('prc_raw_artifacts')->delete();
});

test('retention preserves canonical raw evidence and bounds retired object deletion', function (): void {
    DB::table('prc_raw_artifacts')->delete();
    $store = app(StoreRawFetchArtifactAction::class);
    $pinned = $store->execute('local', 'raw/acquisition/p317', ['id' => 1], 'pinned', 'txt');
    $old = $store->execute('local', 'raw/retired', ['id' => 2], 'retired', 'txt');
    DB::table('prc_raw_artifacts')->update(['last_seen_at' => DB::raw("clock_timestamp() - interval '31 days'")]);
    expect(app(RawArtifactRetention::class)->prune(1))->toBe(1)
        ->and(Storage::disk('local')->exists($pinned))->toBeTrue()
        ->and(Storage::disk('local')->exists($old))->toBeFalse()
        ->and(DB::table('prc_raw_artifacts')->where('path', $pinned)->exists())->toBeTrue();
    DB::table('prc_raw_artifacts')->delete();
});
