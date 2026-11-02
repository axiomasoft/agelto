<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\SubjectKey;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Document\DocumentFeeder;
use App\Services\Procurement\Document\DocumentRevalidator;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\PrepareFixtures;

test('independent revalidation выделяет due cycle и activate только один work без crawler callback', function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    $f = new PrepareFixtures;
    $doc = DB::table('prc_document_versions')->where('id', $f->input->documentVersionId)->value('document_id');
    $id = LedgerFixtures::uuid();
    DB::transaction(function () use ($doc, $id): void {
        app(WorkStoreRepository::class)->storeIntent($id, LedgerFixtures::hash('initial'), StageCode::Download, new SubjectKey(SubjectKind::Document, $doc),
            LedgerFixtures::hash('semantic'), LedgerFixtures::hash('access'), 1, 1, IntentState::Terminal);
        DB::table('prc_documents')->where('id', $doc)->update(['source_document_key' => 'canonical', 'source_key_version' => 1, 'access_schema_version' => 1,
            'access_descriptor_version' => 1, 'reference_observed_at' => now(), 'access_descriptor' => '{"url":"https://example.test/document","expires_at":null}',
            'acquisition_cycle_seq' => 1, 'next_revalidation_due_at' => now()->subSecond()]);
    });
    $intent = app(DocumentRevalidator::class)->allocate($doc);
    expect($intent)->not->toBeNull()->and(app(DocumentRevalidator::class)->allocate($doc))->toBeNull();
    expect(app(DocumentFeeder::class)->reconcile())->toBe(1)
        ->and(DB::table('prc_process_work')->where('stage', 'download')->count())->toBe(1)
        ->and(DB::table('prc_operation_intents')->where('id', $intent->value)->value('cycle_seq'))->toBe(2)
        ->and(DB::table('prc_documents')->where('id', $doc)->value('head_generation'))->toBe(2);
});
