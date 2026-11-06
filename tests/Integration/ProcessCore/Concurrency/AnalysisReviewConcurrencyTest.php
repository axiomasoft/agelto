<?php

declare(strict_types=1);

use App\Actions\Procurement\Ai\ReviewAnalysisAction;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\CorrectionReason;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Correction;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Item;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\StoredDocumentFixtures;

pest()->group('analysis-execution');

beforeEach(fn () => StoredDocumentFixtures::reset());
afterEach(fn () => StoredDocumentFixtures::reset());

function reviewExtraction(DocumentVersion $version, string $name): Analysis
{
    $analysis = Analysis::factory()->create([
        'document_version_id' => $version->id, 'step' => AnalysisStep::Extraction, 'document_type' => 'estimate',
    ]);
    $estimate = Estimate::factory()->create([
        'analysis_id' => $analysis->id, 'document_version_id' => $version->id, 'ordinal' => 1,
    ]);
    Item::factory()->create(['estimate_id' => $estimate->id, 'row_index' => 1, 'name' => $name]);

    return $analysis;
}

/** @param array<string,array{analysis: int, reviewer: int}> $requests */
function simultaneousAnalysisApprovals(array $requests): array
{
    $workers = [];
    foreach ($requests as $name => $request) {
        $workers[$name] = '\Illuminate\Support\Facades\DB::statement("SET application_name=\'analysis-review-approval\'");'
            .'$changed=app(\App\Actions\Procurement\Ai\ReviewAnalysisAction::class)->approve('
            .'analysisId:'.$request['analysis'].',reviewerId:'.$request['reviewer'].');'
            .'return ["changed"=>$changed,"reviewer"=>'.$request['reviewer'].',"pid"=>'
            .'\Illuminate\Support\Facades\DB::selectOne("SELECT pg_backend_pid() AS id")->id];';
    }
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION fixture_review_gold_barrier() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN PERFORM pg_advisory_xact_lock_shared(9420911); RETURN NEW; END $$;
        CREATE TRIGGER fixture_review_gold_barrier BEFORE INSERT ON prc_ai_dataset_examples
            FOR EACH ROW EXECUTE FUNCTION fixture_review_gold_barrier();
        SQL);
    DB::select('SELECT pg_advisory_lock(9420911)');
    $waiting = [];
    try {
        $results = ConcurrentTransactions::concurrently($workers, function () use (&$waiting): void {
            try {
                $deadline = hrtime(true) + 5_000_000_000;
                do {
                    $waiting = DB::select("SELECT pid,wait_event,query FROM pg_stat_activity
                        WHERE application_name='analysis-review-approval' AND wait_event_type='Lock'");
                    if (count($waiting) === 2) {
                        break;
                    }
                    usleep(10000);
                } while (hrtime(true) < $deadline);
            } finally {
                DB::select('SELECT pg_advisory_unlock(9420911)');
            }
        });
    } finally {
        DB::select('SELECT pg_advisory_unlock(9420911)');
        DB::unprepared('DROP TRIGGER fixture_review_gold_barrier ON prc_ai_dataset_examples; DROP FUNCTION fixture_review_gold_barrier()');
    }
    expect($waiting)->toHaveCount(2)
        ->and(count(array_filter($waiting, static fn ($row): bool => $row->wait_event === 'advisory')))->toBe(1)
        ->and(count(array_filter($waiting, static fn ($row): bool => str_contains($row->query, 'prc_document_versions') && $row->wait_event !== 'advisory')))->toBe(1);

    return $results;
}

test('конкурентные одобрения одного анализа сохраняют один gold и исходный reviewer audit', function (): void {
    $analysis = reviewExtraction(DocumentVersion::factory()->create(), 'Одна позиция');
    $first = User::factory()->create();
    $second = User::factory()->create();
    $workIdsBefore = DB::table('prc_process_work')->orderBy('id')->pluck('id')->all();
    $providerRequestIdsBefore = DB::table('llm_provider_requests')->orderBy('logical_request_id')->pluck('logical_request_id')->all();
    $results = simultaneousAnalysisApprovals([
        'first' => ['analysis' => $analysis->id, 'reviewer' => $first->id],
        'second' => ['analysis' => $analysis->id, 'reviewer' => $second->id],
    ]);
    $winner = array_values(array_filter($results, static fn (array $result): bool => $result['changed']));
    $gold = DatasetExample::query()->where('analysis_id', $analysis->id)->sole();
    expect($results['first']['pid'])->not->toBe($results['second']['pid'])
        ->and($winner)->toHaveCount(1)
        ->and($analysis->refresh()->review_status->value)->toBe('approved')
        ->and($analysis->reviewed_by)->toBe($winner[0]['reviewer'])
        ->and($gold->verified_by)->toBe($winner[0]['reviewer'])
        ->and($gold->version)->toBe(1)
        ->and(DB::table('prc_process_work')->orderBy('id')->pluck('id')->all())->toBe($workIdsBefore)
        ->and(DB::table('llm_provider_requests')->orderBy('logical_request_id')->pluck('logical_request_id')->all())->toBe($providerRequestIdsBefore);
    $reviewedAt = $analysis->getRawOriginal('reviewed_at');
    expect(app(ReviewAnalysisAction::class)->approve($analysis->id, $second->id))->toBeFalse()
        ->and($analysis->refresh()->getRawOriginal('reviewed_at'))->toBe($reviewedAt)
        ->and($analysis->reviewed_by)->toBe($winner[0]['reviewer'])
        ->and(DatasetExample::query()->where('analysis_id', $analysis->id)->count())->toBe(1)
        ->and(DB::table('prc_process_work')->orderBy('id')->pluck('id')->all())->toBe($workIdsBefore)
        ->and(DB::table('llm_provider_requests')->orderBy('logical_request_id')->pluck('logical_request_id')->all())->toBe($providerRequestIdsBefore);
});

test('одобрения разных анализов одной версии сериализуют gold versions и сохраняют оба payload', function (): void {
    $version = DocumentVersion::factory()->create();
    $historical = reviewExtraction($version, 'Первая версия');
    $historical->update(['superseded_at' => now()]);
    $current = reviewExtraction($version, 'Вторая версия');
    $reviewer = User::factory()->create();
    $results = simultaneousAnalysisApprovals([
        'historical' => ['analysis' => $historical->id, 'reviewer' => $reviewer->id],
        'current' => ['analysis' => $current->id, 'reviewer' => $reviewer->id],
    ]);
    $examples = DatasetExample::query()->where('document_version_id', $version->id)->orderBy('version')->get();
    expect($results['historical']['pid'])->not->toBe($results['current']['pid'])
        ->and($results['historical']['changed'])->toBeTrue()->and($results['current']['changed'])->toBeTrue()
        ->and($examples->pluck('version')->all())->toBe([1, 2])
        ->and($examples->keyBy('analysis_id')[$historical->id]->gold['estimates'][0]['rows'][0]['name'])->toBe('Первая версия')
        ->and($examples->keyBy('analysis_id')[$current->id]->gold['estimates'][0]['rows'][0]['name'])->toBe('Вторая версия');
});

test('ошибка вставки gold откатывает approval и повтор сохраняет один snapshot', function (): void {
    $analysis = reviewExtraction(DocumentVersion::factory()->create(), 'Для rollback');
    $reviewer = User::factory()->create();
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION fixture_reject_review_gold() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN RAISE EXCEPTION 'fixture gold failure'; END $$;
        CREATE TRIGGER fixture_reject_review_gold BEFORE INSERT ON prc_ai_dataset_examples
            FOR EACH ROW EXECUTE FUNCTION fixture_reject_review_gold();
        SQL);
    try {
        expect(fn () => app(ReviewAnalysisAction::class)->approve($analysis->id, $reviewer->id))->toThrow(QueryException::class)
            ->and($analysis->refresh()->review_status->value)->toBe('unreviewed')
            ->and($analysis->reviewed_by)->toBeNull()->and($analysis->reviewed_at)->toBeNull()
            ->and(DatasetExample::query()->count())->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER fixture_reject_review_gold ON prc_ai_dataset_examples; DROP FUNCTION fixture_reject_review_gold()');
    }
    expect(app(ReviewAnalysisAction::class)->approve($analysis->id, $reviewer->id))->toBeTrue()
        ->and($analysis->refresh()->review_status->value)->toBe('approved')
        ->and(DatasetExample::query()->where('analysis_id', $analysis->id)->count())->toBe(1);
});

test('ошибка review откатывает коррекцию, а новые отправки создают отдельные коррекции', function (): void {
    $analysis = reviewExtraction(DocumentVersion::factory()->create(), 'Для correction');
    $reviewer = User::factory()->create();
    $correction = ['field_path' => '', 'reason' => CorrectionReason::WrongValue->value, 'content' => 'Исправить сумму', 'is_reusable' => true];
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION fixture_reject_review_update() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN IF NEW.review_status='rejected' THEN RAISE EXCEPTION 'fixture review failure'; END IF; RETURN NEW; END $$;
        CREATE TRIGGER fixture_reject_review_update BEFORE UPDATE ON prc_ai_analyses
            FOR EACH ROW EXECUTE FUNCTION fixture_reject_review_update();
        SQL);
    try {
        expect(fn () => app(ReviewAnalysisAction::class)->rejectWithCorrection($analysis->id, $reviewer->id, $correction))->toThrow(QueryException::class)
            ->and($analysis->refresh()->review_status->value)->toBe('unreviewed')
            ->and($analysis->reviewed_by)->toBeNull()->and($analysis->reviewed_at)->toBeNull()
            ->and($analysis->operator_comment)->toBeNull()
            ->and(Correction::query()->count())->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER fixture_reject_review_update ON prc_ai_analyses; DROP FUNCTION fixture_reject_review_update()');
    }
    expect(app(ReviewAnalysisAction::class)->rejectWithCorrection($analysis->id, $reviewer->id, $correction))->toBeTrue()
        ->and($analysis->refresh()->review_status->value)->toBe('rejected')
        ->and($analysis->reviewed_by)->toBe($reviewer->id)->and($analysis->operator_comment)->toBe('Исправить сумму');
    $stored = Correction::query()->where('analysis_id', $analysis->id)->sole();
    expect($stored->document_type)->toBe('estimate')->and($stored->field_path)->toBeNull()
        ->and($stored->created_by)->toBe($reviewer->id)->and($stored->is_reusable)->toBeTrue();
    expect(app(ReviewAnalysisAction::class)->rejectWithCorrection($analysis->id, $reviewer->id, $correction))->toBeTrue()
        ->and(Correction::query()->where('analysis_id', $analysis->id)->count())->toBe(2);
});
