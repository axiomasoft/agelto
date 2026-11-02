<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ScreeningInput;
use App\Enums\Procurement\Process\WorkState;
use App\Models\Llm\LlmCall;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Repositories\Llm\LlmCallReadRepository;
use App\Repositories\Procurement\Ai\AnalysisCallReadRepository;
use App\Repositories\Procurement\Ai\AnalysisRunReadRepository;
use App\Services\Llm\LlmPayloadReader;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Screening\ScreeningHandler;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ScreeningRuntimeFixtures;

pest()->group('t22', 't44', 'p313');

test('canonical read models derive execution and money while result reviews remain bigint projections', function (): void {
    [$work, $lease, $window, $period] = ScreeningRuntimeFixtures::work();
    ScreeningRuntimeFixtures::capture($lease, $window, $period, ScreeningRuntimeFixtures::raw());
    $input = new ScreeningInput($window->run->documentVersionId, $window->run->representationHash, $window->run->recipe->hash);
    $finalizer = app(WorkFinalizer::class);
    $finalizer->finish($lease, app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    $lease = FinalizeFixtures::running($work);
    $outcome = app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    $finalizer->finish($lease, $outcome);
    $finalizer->finish($lease, $outcome);

    $run = app(AnalysisRunReadRepository::class)->query()->where('prc_analysis_runs.id', $window->run->id->value)->sole();
    expect($run)->toBeInstanceOf(AnalysisRun::class)->and($run->getTable())->toBe('prc_analysis_runs')
        ->and($run->getKey())->toBe($window->run->id->value)
        ->and($run->execution_state)->toBe(WorkState::Ready)
        ->and($run->calls)->toBe(1)->and($run->cost_micros)->toBe(2000)
        ->and($run->cost_currency)->toBe('USD')->and($run->prompt_tokens)->toBeNull()
        ->and($run->reserved_micros)->toBe(0);
    $projection = Analysis::query()->sole();
    expect($projection->id)->toBeInt()->and($projection->analysis_run_id)->toBe($run->id)
        ->and($projection->storedScreeningResult()->summary)->toBe('Локальная смета')
        ->and(method_exists($projection, 'acquireLease'))->toBeFalse();

    $call = LlmCall::query()->sole();
    expect($call->getTable())->toBe('llm_provider_calls')->and($call->getKey())->toBe($call->physical_call_id)
        ->and($call->cost_actual)->toBe('0.00200001')->and(method_exists($call, 'analysis'))->toBeFalse();
    expect(app(AnalysisCallReadRepository::class)->findInRun($call->physical_call_id, $run->id)?->getKey())->toBe($call->getKey())
        ->and(app(AnalysisCallReadRepository::class)->findInRun($call->physical_call_id, LedgerFixtures::uuid()->value))->toBeNull();
    $payload = app(LlmPayloadReader::class)->receipt($call);
    expect($payload->available)->toBeTrue()->and($payload->content)->not->toContain('send_authorization_token');
    $usage = app(LlmCallReadRepository::class)->usageByAxes(CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay(), ['provider', 'purpose']);
    expect($usage)->toHaveCount(1)->and($usage[0]['calls'])->toBe(1)
        ->and($usage[0]['cost_micros'])->toBe(2000)->and($usage[0]['reserved_micros'])->toBe(0);
    expect(fn () => $run->save())->toThrow(LogicException::class, 'durable workflow owner')
        ->and(fn () => $run->delete())->toThrow(LogicException::class, 'provenance owner')
        ->and(fn () => $call->save())->toThrow(LogicException::class, 'RequestLedger')
        ->and(fn () => $call->delete())->toThrow(LogicException::class, 'financial retention authority')
        ->and(DB::table('prc_ai_analyses')->count())->toBe(1)
        ->and(DB::table('llm_provider_calls')->count())->toBe(1);
    expect(fn () => DB::transaction(fn () => DB::table('prc_ai_analyses')->where('id', $projection->id)
        ->update(['result' => '{"overwritten":true}'])))->toThrow(QueryException::class, 'Accepted analysis result is immutable');
    expect(fn () => DB::transaction(fn () => DB::table('prc_ai_analyses')->where('id', $projection->id)->delete()))
        ->toThrow(QueryException::class, 'Accepted analysis result is immutable');
    DB::table('prc_ai_analyses')->where('id', $projection->id)->update(['review_status' => 'approved', 'operator_comment' => 'reviewed fixture']);
    expect($projection->fresh()->review_status->value)->toBe('approved')
        ->and($projection->fresh()->storedScreeningResult()->summary)->toBe('Локальная смета');
    Http::assertNothingSent();
});
