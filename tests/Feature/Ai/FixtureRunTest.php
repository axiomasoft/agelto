<?php

declare(strict_types=1);

use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Platform\Platform;
use App\Models\User;
use App\Repositories\Procurement\Document\DocumentWorkRepository;
use App\Services\Procurement\Fixture\RunFixtureAnalysisAction;
use App\Services\Procurement\Fixture\WorkingCopyProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;

beforeEach(function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    Platform::query()->firstOrCreate(['code' => Platform::TRAINING_CODE], Platform::factory()->training()->raw());
    config(['ai.screening.enabled' => true]);
    Storage::fake(config('ai.fixtures.disk'));
    Storage::fake(config('crawler.documents_disk'));
    Queue::fake();
});

function fixtureRunFixture(string $code): Fixture
{
    $bytes = 'fixture contents '.$code;
    $path = Fixture::storagePathFor($code, 'xlsx');
    Storage::disk(config('ai.fixtures.disk'))->put($path, $bytes);

    return Fixture::factory()->create(['code' => $code, 'storage_path' => $path, 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes)]);
}

test('fixture request создаёт canonical рабочую копию и manual run с инициатором', function (): void {
    $fixture = fixtureRunFixture('EST-XLSX-RUN-001');
    $user = User::factory()->create();
    app(RunFixtureAnalysisAction::class)->run($fixture, $user->id);
    $document = Document::query()->where('external_id', 'fixture:'.$fixture->code)->sole();
    $version = DocumentVersion::query()->where('document_id', $document->id)->sole();
    $work = DB::table('prc_process_work')->where('document_version_id', $version->id)->sole();
    $run = DB::table('prc_process_runs')->where('id', $work->origin_run_id)->sole();
    expect($document->platform_id)->toBe(Platform::training()->id)
        ->and($fixture->refresh()->document_id)->toBe($document->id)
        ->and($version->content_hash)->toBe($fixture->sha256)
        ->and($version->getAttribute('blob_id'))->not->toBeNull()
        ->and([$work->stage, $work->state, $run->kind, $run->requested_by])->toBe(['prepare', 'ready', 'manual', $user->id]);
    Queue::assertNothingPushed(); // Process outbox owns delivery, not an ordinary preparation job.
});

test('prepare producer сохраняет auto без инициатора и manual с инициатором в durable run', function (): void {
    $a = DocumentVersion::factory()->create();
    $b = DocumentVersion::factory()->create();
    $user = User::factory()->create();
    app(DocumentWorkRepository::class)->prepare($a->document_id);
    app(DocumentWorkRepository::class)->prepare($b->document_id, $user->id, manual: true);
    $runs = DB::table('prc_process_runs')->orderBy('requested_by')->get();
    expect($runs)->toHaveCount(2);
    $auto = $runs->firstWhere('kind', 'profile');
    $manual = $runs->firstWhere('kind', 'manual');
    expect($auto->requested_by)->toBeNull()->and($manual->requested_by)->toBe($user->id)
        ->and(DB::table('prc_process_work')->where('state', 'ready')->count())->toBe(2);
});

test('оба вызывающих пути (estimate:gold:import и «Прогнать») резолвят одну и ту же рабочую копию по sha256 (D41, единственность сейма)', function (): void {
    $fixture = fixtureRunFixture('EST-XLSX-RUN-005');
    $this->artisan('estimate:gold:import', ['--code' => [$fixture->code]])->assertSuccessful();
    $a = DocumentVersion::query()->where('document_id', $fixture->refresh()->document_id)->sole();
    app(RunFixtureAnalysisAction::class)->run($fixture);
    $b = DocumentVersion::query()->where('document_id', $fixture->refresh()->document_id)->sole();
    expect($b->id)->toBe($a->id)->and($b->document_id)->toBe($a->document_id)->and($b->content_hash)->toBe($fixture->sha256)
        ->and(DB::table('prc_process_work')->where('document_version_id', $b->id)->count())->toBe(1);
});

test('working copy A B A сохраняет три canonical revisions и current head вместо выбора historical hash', function (): void {
    $fixture = fixtureRunFixture('EST-XLSX-RUN-ABA');
    $a = 'fixture contents '.$fixture->code;
    foreach ([$a, 'changed fixture bytes', $a] as $bytes) {
        Storage::disk(config('ai.fixtures.disk'))->put($fixture->storage_path, $bytes);
        $fixture->update(['sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes)]);
        [, $version] = app(WorkingCopyProvisioner::class)->provision($fixture->refresh(), false);
        expect($version->content_hash)->toBe(hash('sha256', $bytes));
    }
    expect(DocumentVersion::query()->where('document_id', $fixture->document_id)->count())->toBe(3)
        ->and(Document::query()->findOrFail($fixture->document_id)->getAttribute('current_version_id'))->toBe($version->id)
        ->and(DB::table('prc_document_blobs')->count())->toBe(2);
});
