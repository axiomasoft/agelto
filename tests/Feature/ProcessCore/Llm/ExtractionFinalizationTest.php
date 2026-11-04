<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\CancelWork;
use App\Dto\Llm\Requests\RequestIdentity;
use App\Dto\Llm\Requests\StructuredResult;
use App\Dto\Procurement\Ai\ExtractionOutput;
use App\Dto\Procurement\Ai\ExtractionProgress;
use App\Dto\Procurement\Ai\RowRange;
use App\Dto\Procurement\Ai\ValidatedChunk;
use App\Dto\Procurement\Process\ExtractionWindowInput;
use App\Dto\Procurement\Process\MissingParts;
use App\Dto\Procurement\Process\NoProviderRecovery;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\StagePartial;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\StructuredDisposition;
use App\Exceptions\Llm\OperationDenied;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Procurement\Extraction\AnalysisCallCoordinator;
use App\Services\Procurement\Extraction\ExtractionHandler;
use App\Services\Procurement\Extraction\ValidatedOutputStore;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\AiFixtures;
use Tests\Support\ProcessCore\ChunkAssemblyFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\ExtractionRuntimeFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FinancialFixtures;

pest()->group('structured-extraction-contract', 'provider-and-worker-recovery', 'analysis-execution');

test('durable raw recovery parses only the pinned receipt and atomically accepts chunk coverage and directive', function (): void {
    [$work, $lease, $window, $period] = ExtractionRuntimeFixtures::work();
    ExtractionRuntimeFixtures::capture($lease, $window, $period, AiFixtures::raw());
    FinalizeFixtures::expire($work, 600);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);
    $lease = FinalizeFixtures::running($work);
    expect($lease->checkpoint?->providerRecoveryPlan)->toBeInstanceOf(ProviderRecoveryPlan::class);
    $input = new ExtractionWindowInput($window->run->id, $window->windowKey, $window->run->recipe->hash);
    $outcome = app(ExtractionHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    expect($outcome)->toBeInstanceOf(StagePartial::class);
    DB::unprepared("CREATE FUNCTION fixture_chunk_reject() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'fixture chunk journal failure'; END \$\$");
    DB::unprepared('CREATE TRIGGER fixture_chunk_reject BEFORE INSERT ON prc_process_transitions FOR EACH ROW EXECUTE FUNCTION fixture_chunk_reject()');
    try {
        expect(fn () => app(WorkFinalizer::class)->finish($lease, $outcome))->toThrow(QueryException::class, 'fixture chunk journal failure');
        expect(DB::table('prc_analysis_chunks')->count())->toBe(0)
            ->and(DB::table('prc_analysis_llm_requests')->where('recovery_active', true)->count())->toBe(1)
            ->and(ExecutionFixtures::work($work)->state)->toBe('running');
    } finally {
        DB::unprepared('DROP TRIGGER fixture_chunk_reject ON prc_process_transitions');
        DB::unprepared('DROP FUNCTION fixture_chunk_reject()');
    }
    expect(app(WorkFinalizer::class)->finish($lease, $outcome)->state?->value)->toBe('ready')
        ->and(DB::table('prc_analysis_chunks')->count())->toBe(1)
        ->and(DB::table('prc_analysis_llm_requests')->where('recovery_active', true)->count())->toBe(0)
        ->and(DB::table('llm_provider_calls')->count())->toBe(1)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(1);
    $next = FinalizeFixtures::running($work);
    expect($next->checkpoint?->providerRecoveryPlan)->toBeInstanceOf(NoProviderRecovery::class)
        ->and($next->checkpoint?->stageProgress->checkpoint->pending)->toBe([])
        ->and(app(WorkFinalizer::class)->finish($lease, $outcome)->status->value)->toBe('lost_ownership');
    Http::assertNothingSent();
});

test('ordinary partial progress preserves the pinned directive', function (): void {
    [$work, $lease, $window, $period] = ExtractionRuntimeFixtures::work();
    ExtractionRuntimeFixtures::capture($lease, $window, $period, AiFixtures::raw());
    FinalizeFixtures::expire($work, 600);
    app(RecoveryReconciler::class)->recoverExpiredRunning();
    $lease = FinalizeFixtures::running($work);
    $plan = $lease->checkpoint?->providerRecoveryPlan;
    app(WorkFinalizer::class)->finish($lease, new StagePartial(new ExtractionProgress, new MissingParts(['slice'])));
    $next = FinalizeFixtures::running($work);
    expect($next->checkpoint?->providerRecoveryPlan)->toEqual($plan)
        ->and(DB::table('prc_analysis_chunks')->count())->toBe(0)
        ->and(DB::table('llm_provider_calls')->count())->toBe(1);
});

test('MAX_TOKENS commits only a left-first split and restart never accepts the billed prefix', function (): void {
    [$work, $lease, $window, $period] = ExtractionRuntimeFixtures::work(new RowRange(0, 4));
    ExtractionRuntimeFixtures::capture($lease, $window, $period, '{prefix:', 'MAX_TOKENS');
    $input = new ExtractionWindowInput($window->run->id, $window->windowKey, $window->run->recipe->hash);
    $handle = static fn ($lease) => app(ExtractionHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    $outcome = $handle($lease);
    expect($outcome->checkpoint->checkpoint->pending)->toEqual([
        new RowRange(0, 2), new RowRange(2, 4),
    ]);
    app(WorkFinalizer::class)->finish($lease, $outcome);
    expect(DB::table('prc_analysis_chunks')->count())->toBe(0);
    foreach ([0, 2] as $start) {
        $lease = FinalizeFixtures::running($work);
        $raw = json_decode(AiFixtures::raw(), true, flags: JSON_THROW_ON_ERROR);
        foreach ($raw['rows'] as &$row) {
            $row['row']['row_index'] += $start;
            $row['source_anchor'] = 'p0:r'.$row['row']['row_index'];
        }
        unset($row);
        $range = new RowRange($start, $start + 2);
        ExtractionRuntimeFixtures::capture($lease, $window, $period, json_encode($raw, JSON_THROW_ON_ERROR), range: $range);
        $outcome = $handle($lease);
        expect($outcome->checkpoint->blocked)->toBeNull();
        app(WorkFinalizer::class)->finish($lease, $outcome);
    }
    $lease = FinalizeFixtures::running($work);
    expect($lease->checkpoint->stageProgress->checkpoint->pending)->toBe([])
        ->and($lease->checkpoint->stageProgress->checkpoint->acceptedChunks)->toHaveCount(2)
        ->and(DB::table('prc_analysis_chunks')->count())->toBe(2)
        ->and(DB::table('llm_provider_calls')->count())->toBe(3)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(3)
        ->and(DB::table('prc_analysis_runs')->value('publication_status'))->toBe('unpublished');
    Http::assertNothingSent();
});

test('schema and semantic reasks remain bounded across deliveries without consuming item failures', function (string $defect, string $reason): void {
    [$work, $lease, $window, $period] = ExtractionRuntimeFixtures::work();
    $input = new ExtractionWindowInput($window->run->id, $window->windowKey, $window->run->recipe->hash);
    $raw = json_decode(AiFixtures::raw(), true, flags: JSON_THROW_ON_ERROR);
    match ($defect) {
        'schema' => $raw['unexpected'] = true,
        'required' => $raw['rows'][0] = array_diff_key($raw['rows'][0], ['source_anchor' => true]),
        'null' => $raw['rows'][0]['source_anchor'] = null,
        'enum' => $raw['rows'][0]['row']['row_type'] = 'unknown-row-type',
        'precision' => $raw['rows'][0]['row']['unit_price_curr'] = 1.25,
        'semantic' => array_pop($raw['rows']),
        'json' => null,
    };
    for ($ordinal = 0; $ordinal < 2; $ordinal++) {
        ExtractionRuntimeFixtures::capture($lease, $window, $period, $defect === 'json' ? '{invalid' : json_encode($raw, JSON_THROW_ON_ERROR), ordinal: $ordinal);
        $outcome = app(ExtractionHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
        app(WorkFinalizer::class)->finish($lease, $outcome);
        $lease = FinalizeFixtures::running($work);
    }
    $outcome = app(ExtractionHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    app(WorkFinalizer::class)->finish($lease, $outcome);
    expect(ExecutionFixtures::work($work)->state)->toBe('blocked')
        ->and(ExecutionFixtures::work($work)->reason_code)->toBe($reason)
        ->and(ExecutionFixtures::work($work)->item_failures)->toBe(0)
        ->and(DB::table('llm_provider_requests')->count())->toBe(2)
        ->and(DB::table('llm_provider_requests')->max('semantic_reask_ordinal'))->toBe(1)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(2)
        ->and(DB::table('prc_analysis_chunks')->count())->toBe(0);
})->with([['schema', 'ai_response_invalid'], ['json', 'ai_response_invalid'], ['required', 'ai_response_invalid'],
    ['null', 'ai_response_invalid'], ['enum', 'ai_response_invalid'], ['precision', 'ai_response_invalid'], ['semantic', 'semantic_review_required']]);

test('refusal empty unsupported and exhausted split outputs preserve billing and block without reasking or publication', function (string $case, string $reason): void {
    [$work, $lease, $window, $period] = ExtractionRuntimeFixtures::work($case === 'minimum' ? new RowRange(0, 1) : null);
    $candidates = match ($case) {
        'refusal' => [['finishReason' => 'SAFETY']],
        'empty_candidates' => [],
        'empty_rows' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '{"rows":[],"totals":[]}']]]]],
        'unsupported' => [['finishReason' => 'CONTINUATION']],
        'tool' => [['finishReason' => 'STOP', 'content' => ['parts' => [['functionCall' => ['name' => 'unexpected_tool']]]]]],
        'minimum' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => '{partial-prefix']]]]],
    };
    ExtractionRuntimeFixtures::captureEnvelope($lease, $window, $period, json_encode(['fixture_receipt' => 1, 'outcome' => 'success',
        'cost' => '0.00200001', 'candidates' => $candidates], JSON_THROW_ON_ERROR));
    $input = new ExtractionWindowInput($window->run->id, $window->windowKey, $window->run->recipe->hash);
    app(WorkFinalizer::class)->finish($lease, app(ExtractionHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    $lease = FinalizeFixtures::running($work);
    app(WorkFinalizer::class)->finish($lease, app(ExtractionHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    expect(ExecutionFixtures::work($work)->state)->toBe('blocked')
        ->and(ExecutionFixtures::work($work)->reason_code)->toBe($reason)
        ->and(ExecutionFixtures::work($work)->item_failures)->toBe(0)
        ->and(DB::table('llm_provider_requests')->count())->toBe(1)
        ->and(DB::table('llm_provider_requests')->max('semantic_reask_ordinal'))->toBe(0)
        ->and(DB::table('llm_budget_reservations')->value('state'))->toBe('settled')
        ->and(DB::table('prc_analysis_chunks')->count())->toBe(0)
        ->and(DB::table('prc_analysis_runs')->value('publication_status'))->toBe('unpublished');
})->with([['refusal', 'model_refusal'], ['empty_candidates', 'model_response_incomplete'], ['empty_rows', 'model_response_incomplete'],
    ['unsupported', 'unsupported_provider_outcome'], ['tool', 'unsupported_provider_outcome'], ['minimum', 'extraction_incomplete']]);

test('a conflicting durable chunk blocks with an atomic incident and never overwrites accepted bytes or consumes recovery', function (): void {
    [$work, $lease, $window, $period] = ExtractionRuntimeFixtures::work();
    ExtractionRuntimeFixtures::capture($lease, $window, $period, AiFixtures::raw());
    $input = new ExtractionWindowInput($window->run->id, $window->windowKey, $window->run->recipe->hash);
    $outcome = app(ExtractionHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    // Deliberate inconsistent pre-existing key: exercise the guard rather than assuming corruption cannot happen.
    $conflict = ChunkAssemblyFixtures::chunk($window, name: 'Conflicting accepted source claim');
    $conflict = new ValidatedChunk($conflict->id, $window, $conflict->range, 0, AiFixtures::output($conflict->canonicalRows),
        new StructuredResult(StructuredDisposition::Accepted, ExtractionOutput::from(json_decode($conflict->canonicalRows, true, flags: JSON_THROW_ON_ERROR))));
    AiFixtures::storeChunk($conflict);
    expect(app(WorkFinalizer::class)->finish($lease, $outcome)->state?->value)->toBe('blocked')
        ->and(ExecutionFixtures::work($work)->reason_code)->toBe('chunk_conflict')
        ->and(DB::table('prc_process_incidents')->where('kind', 'chunk_conflict')->count())->toBe(1)
        ->and(DB::table('prc_analysis_llm_requests')->where('recovery_active', true)->count())->toBe(1)
        ->and(DB::table('prc_analysis_chunks')->count())->toBe(1)
        ->and(DB::table('prc_analysis_chunks')->value('id'))->toBe($conflict->id->value)
        ->and(DB::table('prc_analysis_runs')->value('publication_status'))->toBe('unpublished');
});

test('changed head or hard cancellation rejects an already validated chunk with zero publication', function (bool $cancel): void {
    [$work, $lease, $window, $period] = ExtractionRuntimeFixtures::work();
    ExtractionRuntimeFixtures::capture($lease, $window, $period, AiFixtures::raw());
    $input = new ExtractionWindowInput($window->run->id, $window->windowKey, $window->run->recipe->hash);
    $outcome = app(ExtractionHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    if ($cancel) {
        app(CancelWork::class)->execute($work, 'fixture hard cancel');
    } else {
        // The source head changes while the former owner holds validated historical output.
        DB::update('UPDATE prc_documents SET head_generation=head_generation+1 WHERE id=(SELECT document_id FROM prc_document_versions WHERE id=?)', [$window->run->documentVersionId]);
    }
    $result = app(WorkFinalizer::class)->finish($lease, $outcome);
    expect($result->status->value)->toBe($cancel ? 'lost_ownership' : 'applied')
        ->and(ExecutionFixtures::work($work)->state)->toBe($cancel ? 'cancelled' : 'superseded')
        ->and(DB::table('prc_analysis_chunks')->count())->toBe(0)
        ->and(DB::table('prc_analysis_runs')->value('publication_status'))->toBe('unpublished')
        ->and(DB::table('llm_budget_reservations')->value('state'))->toBe('settled');
})->with([false, true]);

test('two PostgreSQL processes accepting the same chunk commit one progress and one consumption', function (): void {
    [$work, $lease, $window, $period] = ExtractionRuntimeFixtures::work();
    ExtractionRuntimeFixtures::capture($lease, $window, $period, AiFixtures::raw());
    $input = new ExtractionWindowInput($window->run->id, $window->windowKey, $window->run->recipe->hash);
    $outcome = app(ExtractionHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    $payload = base64_encode(serialize([$lease, $outcome]));
    $code = '\Tests\Support\ProcessCore\ExtractionRuntimeFixtures::bind();'
        .'[$lease,$outcome]=unserialize(base64_decode('.var_export($payload, true).'));'
        .'$result=app(\App\Services\Procurement\Process\WorkFinalizer::class)->finish($lease,$outcome);'
        .'return ["status"=>$result->status->value,"pid"=>\Illuminate\Support\Facades\DB::selectOne("SELECT pg_backend_pid() AS id")->id];';
    $results = ConcurrentTransactions::concurrently(['first' => $code, 'second' => $code]);
    $statuses = [$results['first']['status'], $results['second']['status']];
    sort($statuses);
    expect($statuses)->toBe(['applied', 'lost_ownership'])
        ->and($results['first']['pid'])->not->toBe($results['second']['pid'])
        ->and(DB::table('prc_analysis_chunks')->count())->toBe(1)
        ->and(DB::table('prc_analysis_llm_requests')->where('recovery_active', true)->count())->toBe(0)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(1)
        ->and(DB::table('llm_provider_calls')->count())->toBe(1);
});

test('authorization rejects wrong subrange and an unearned semantic ordinal before reserving money', function (): void {
    expect(fn () => new ExtractionProgress(reasks: [hash('sha256', 'range') => 2]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ExtractionProgress(depths: [hash('sha256', 'range') => 17]))->toThrow(InvalidArgumentException::class);
    [$work, $lease, $window, $period] = ExtractionRuntimeFixtures::work();
    foreach ([[hash('sha256', 'wrong-range'), 0], [$window->subrangeInputHash($window->range)->hex, 1]] as [$hash, $ordinal]) {
        $plan = new ProviderOperationPlan(new RequestIdentity(
            $window->run->recipe->hash->hex, $hash, OperationPurpose::Generate, $ordinal, $window->run->id->value),
            $window->run->recipe->llm, FinancialFixtures::pricing(), $work->value->value);
        expect(fn () => app(AnalysisCallCoordinator::class)->authorize($lease, $window->run->id, $plan, $period))
            ->toThrow(OperationDenied::class, 'analysis_subrange_drift');
    }
    expect(DB::table('llm_provider_requests')->count())->toBe(0)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(0);
});

test('validated output uses accounted immutable blobs and no object IO inside a DB transaction', function (): void {
    [$work, $lease] = ExtractionRuntimeFixtures::work();
    $context = app(ExecutionContextFactory::class)->open($lease);
    $store = app(ValidatedOutputStore::class);
    $canonical = '{"fixture":"immutable-output"}';
    $before = (int) DB::table('prc_storage_domains')->sum('accounted_durable_bytes');
    $ref = $store->put($canonical, $context);
    expect($store->put($canonical, $context))->toEqual($ref)
        ->and(Storage::disk($ref->disk)->get($ref->key))->toBe($canonical)
        ->and((int) DB::table('prc_storage_domains')->sum('accounted_durable_bytes'))->toBe($before + strlen($canonical))
        ->and(fn () => DB::transaction(fn () => $store->put($canonical, $context)))->toThrow(LogicException::class);
    Storage::disk($ref->disk)->put($ref->key, 'conflicting content');
    expect($store->put($canonical, $context))->toEqual($ref)
        ->and(Storage::disk($ref->disk)->get($ref->key))->toBe($canonical)
        ->and((int) DB::table('prc_storage_domains')->sum('accounted_durable_bytes'))->toBe($before + strlen($canonical));
});
