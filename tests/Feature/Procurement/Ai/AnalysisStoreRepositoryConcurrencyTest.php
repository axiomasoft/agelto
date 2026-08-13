<?php

declare(strict_types=1);

use Tests\Support\Postgres\ConcurrentTransactions;

test('два PostgreSQL worker получают один active analysis для версии и шага', function (): void {
    $fixture = ConcurrentTransactions::execute(<<<'PHP'
$version = \App\Models\Procurement\Document\DocumentVersion::factory()->create();
app(\App\Repositories\Procurement\Ai\AnalysisRunStoreRepository::class)->start(
    documentVersionId: $version->id,
    mode: \App\Enums\Procurement\Ai\RunMode::Auto,
);

return ['version_id' => $version->id, 'document_id' => $version->document_id];
PHP);

    try {
        $workers = ConcurrentTransactions::concurrently([
            'first' => analysisStoreConcurrentWorker(documentVersionId: $fixture['version_id']),
            'second' => analysisStoreConcurrentWorker(documentVersionId: $fixture['version_id']),
        ]);
        $state = ConcurrentTransactions::execute(<<<PHP
return ['count' => \App\Models\Procurement\Ai\Analysis::query()
    ->active()
    ->where('document_version_id', {$fixture['version_id']})
    ->where('step', 'screening')
    ->count()];
PHP);

        expect($workers['first']['analysis_id'])->toBe($workers['second']['analysis_id'])
            ->and($workers['first']['backend_pid'])->not->toBe($workers['second']['backend_pid'])
            ->and($state['count'])->toBe(1);
    } finally {
        ConcurrentTransactions::execute(<<<PHP
\App\Models\Procurement\Ai\Analysis::query()->where('document_version_id', {$fixture['version_id']})->delete();
\App\Models\Procurement\Ai\AnalysisRun::query()->where('document_version_id', {$fixture['version_id']})->delete();
\App\Models\Procurement\Document\DocumentVersion::query()->whereKey({$fixture['version_id']})->delete();
\App\Models\Procurement\Document\Document::query()->whereKey({$fixture['document_id']})->delete();

return ['cleaned' => true];
PHP);
    }
});

function analysisStoreConcurrentWorker(int $documentVersionId): string
{
    return <<<PHP
\$analysis = app(\App\Repositories\Procurement\Ai\AnalysisStoreRepository::class)->firstOrCreate(
    documentVersionId: {$documentVersionId},
    step: \App\Enums\Procurement\Ai\AnalysisStep::Screening,
);

return ['analysis_id' => \$analysis->id, 'backend_pid' => \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()')];
PHP;
}
