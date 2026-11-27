<?php

declare(strict_types=1);

use App\Dto\Procurement\Document\Download\DocumentHead;
use App\Enums\Procurement\Process\Core\WorkState;
use App\Enums\Procurement\Process\Outcomes\CompletionStatus;
use App\Models\Procurement\Document\Document;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\PrepareFixtures;
use Tests\Support\ProcessCore\StubPdfTools;

test('новая immutable версия при running prepare fence запрещает checkpoint и late publication старой версии', function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    $f = new PrepareFixtures;
    $ctx = $f->context();
    $tools = new StubPdfTools(1);
    $outcome = $f->handler($tools)->execute($f->input, $ctx);
    app(WorkFinalizer::class)->finish($ctx->lease(), $outcome);
    $ctx = $f->context();
    $outcome = $f->handler($tools)->execute($f->input, $ctx);
    $doc = DB::table('prc_document_versions')->where('id', $f->input->documentVersionId)->value('document_id');
    DocumentStorageFixtures::observe(new DocumentHead($doc, 2, $f->input->documentVersionId),
        DocumentStorageFixtures::upload('new source bytes', DocumentStorageFixtures::domain()));
    $result = app(WorkFinalizer::class)->finish($ctx->lease(), $outcome);
    expect($result->status)->toBe(CompletionStatus::Applied)->and($result->state)->toBe(WorkState::Superseded)
        ->and(DB::table('prc_document_representations')->where('range_key', 'complete')->count())->toBe(0)
        ->and(DB::table('prc_process_reservations')->count())->toBe(0);
});

// Retained fake-AI compatibility assertions; typed document revalidation uses the head fence above.
