<?php

declare(strict_types=1);

use Tests\Support\Postgres\ConcurrentTransactions;

test('два PostgreSQL worker получают один active analysis для версии и шага', function (): void {
    $fixture = ConcurrentTransactions::execute(<<<'PHP'
$platform = \App\Models\Procurement\Platform\Platform::factory()->create(attributes: [
    'code' => 'analysis-store-concurrency',
]);
$registry = \App\Models\Procurement\Platform\Registry::factory()
    ->for($platform)
    ->create(attributes: ['code' => 'analysis-store-concurrency']);
$procurement = \App\Models\Procurement\Procurement::factory()->create(attributes: [
    'registry_id' => $registry->id,
]);
$document = \App\Models\Procurement\Document\Document::factory()->create(attributes: [
    'procurement_id' => $procurement->id,
    'platform_id' => $platform->id,
]);
$version = \App\Models\Procurement\Document\DocumentVersion::factory()->create(attributes: [
    'document_id' => $document->id,
]);
app(\App\Repositories\Procurement\Ai\AnalysisRunStoreRepository::class)->start(
    documentVersionId: $version->id,
    mode: \App\Enums\Procurement\Ai\RunMode::Auto,
);

return [
    'version_id' => $version->id,
    'document_id' => $document->id,
    'procurement_id' => $procurement->id,
    'registry_id' => $registry->id,
    'platform_id' => $platform->id,
];
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
\App\Models\Procurement\Procurement::query()->whereKey({$fixture['procurement_id']})->delete();
\App\Models\Procurement\Platform\Registry::query()->whereKey({$fixture['registry_id']})->delete();
\App\Models\Procurement\Platform\Platform::query()->whereKey({$fixture['platform_id']})->delete();

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
