<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\SafeUrl;
use App\Enums\Llm\ProviderSendDenial;
use App\Enums\Llm\ProviderTransportFailure;
use App\Enums\Procurement\Process\ControlMode;
use App\Repositories\Llm\Request\RequestRepository;
use App\Services\Llm\Execution\ProviderTransportOutcome;
use App\Services\Llm\Execution\ProviderWireRequest;
use App\Services\Llm\Execution\StructuredCallExecutor;
use App\Services\Llm\Providers\GeminiGenerateContentClient;
use App\Services\Procurement\Extraction\AnalysisCallCoordinator;
use App\Services\Procurement\Network\PinnedCurlHandler;
use App\Services\Procurement\Process\RecoveryReconciler;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\ProviderSendFixtures;

pest()->group('p310', 't15', 't22', 't35');

beforeEach(function (): void {
    $this->server = new WireFixture;
});

afterEach(function (): void {
    $this->server->stop();
});

test('Gemini pure compile и настоящий wire совпадают; один joint ID, receipt и network release', function (): void {
    $f = new ProviderSendFixtures($this->server);
    $wire = (new GeminiGenerateContentClient($this->server->url('/')->origin->value()))->compile($f->plan);
    expect($wire->fingerprint)->toBe($f->wire->fingerprint);
    $payload = json_decode($wire->body, true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($payload))->toBe(['contents', 'generationConfig', 'systemInstruction'])
        ->and($payload['generationConfig']['responseMimeType'])->toBe('application/json')
        ->and($payload['generationConfig']['responseJsonSchema'])->toBe(json_decode(json_encode($f->plan->generation->schema->definition), true))
        ->and($payload['generationConfig']['maxOutputTokens'])->toBe(4096)
        ->and($payload['generationConfig']['thinkingConfig'])->toBe(['includeThoughts' => false, 'thinkingBudget' => 128])
        ->and($payload['contents'][0]['role'])->toBe('user');
    $result = app(StructuredCallExecutor::class)->execute($f->operation, $wire, $f->gate());
    expect($result)->toBeInstanceOf(ProviderTransportOutcome::class)
        ->and($result->status)->toBe(200)
        ->and($this->server->receipts())->toHaveCount(1)
        ->and($this->server->receipts()[0]['body_sha256'])->toBe($wire->bodyHash)
        ->and($this->server->receipts()[0]['method'])->toBe('POST')
        ->and($this->server->receipts()[0]['headers']['x-goog-api-key'])->toBe('fixture-only-key')
        ->and(DB::table('prc_request_attempts')->sole()->request_id)->toBe($f->operation->physicalCallId)
        ->and(DB::table('prc_network_permits')->sole()->state)->toBe('released')
        ->and(app(RequestRepository::class)->get($f->operation->physicalCallId)->requestState)->toBe('completed')
        ->and(DB::table('llm_budget_reservations')->sole()->state)->toBe('settled');
});

test('повторный gate и single-use capability не отправляют второй HTTP, clone/serialize закрыты', function (): void {
    $f = new ProviderSendFixtures($this->server);
    $gate = $f->gate();
    $claim = $gate->claimSend($f->operation, $f->wire);
    expect($claim->transport)->not->toBeNull()
        ->and($gate->claimSend($f->operation, $f->wire)->denial)->toBe(ProviderSendDenial::AlreadyClaimed);
    expect(fn () => serialize($claim->transport))->toThrow(LogicException::class)
        ->and(fn () => clone $claim->transport)->toThrow(LogicException::class);
    // Soft pause after joint claim allows only the operation already granted.
    ExecutionFixtures::setMode('documents', ControlMode::Paused);
    $outcome = $claim->transport->sendOnce($f->wire);
    expect($outcome->status)->toBe(200)
        ->and(fn () => $claim->transport->sendOnce($f->wire))->toThrow(LogicException::class)
        ->and($this->server->receipts())->toHaveCount(1);
    $gate->recordTransportOutcome($f->operation->physicalCallId, $outcome);
    $gate->recordTransportOutcome($f->operation->physicalCallId, $outcome);
    expect(DB::table('prc_request_attempts')->count())->toBe(1)
        ->and(DB::table('prc_network_permits')->sole()->state)->toBe('released');
});

test('два PHP процесса получают одну capability и отправляют ровно один физический запрос', function (): void {
    $f = new ProviderSendFixtures($this->server);
    $code = $f->worker('$claim=$gate->claimSend($operation,$wire); if ($claim->transport!==null) { $out=$claim->transport->sendOnce($wire); $gate->recordTransportOutcome($operation->physicalCallId,$out); } return ["granted"=>$claim->transport!==null,"pid"=>\\Illuminate\\Support\\Facades\\DB::selectOne("SELECT pg_backend_pid() AS pid")->pid];');
    $results = ConcurrentTransactions::concurrently(['first' => $code, 'second' => $code]);
    expect($results['first']['pid'])->not->toBe($results['second']['pid'])
        ->and((int) $results['first']['granted'] + (int) $results['second']['granted'])->toBe(1)
        ->and($this->server->receipts())->toHaveCount(1)
        ->and(DB::table('prc_request_attempts')->count())->toBe(1)
        ->and(DB::table('llm_provider_calls')->whereNotNull('send_claimed_at')->count())->toBe(1);
});

test('физический cURL pump отвергает повторный вход Guzzle после единственного локального HTTP', function (): void {
    $f = new ProviderSendFixtures($this->server);
    expect($f->gate()->claimSend($f->operation, $f->wire)->transport)->not->toBeNull();
    // Exercise the very handler CurlFactory can re-enter, under the one committed joint ID.
    $handler = new PinnedCurlHandler;
    $request = new Request('POST', $f->wire->endpoint, ['Content-Type' => 'application/json', 'Content-Length' => (string) strlen($f->wire->body)], $f->wire->body);
    $port = $this->server->ports['http'];
    $options = ['proxy' => '', 'timeout' => 4, 'allow_redirects' => false, 'curl' => [CURLOPT_CONNECT_TO => ['fixture.test:'.$port.':127.0.0.1:'.$port]]];
    expect($handler($request, $options)->wait()->getStatusCode())->toBe(200)
        ->and(fn () => $handler($request, $options))->toThrow(RuntimeException::class, 'already consumed')
        ->and($this->server->receipts())->toHaveCount(1)
        ->and($this->server->receipts()[0]['body_sha256'])->toBe($f->wire->bodyHash)
        ->and(DB::table('prc_request_attempts')->count())->toBe(1);
});

test('ожидание slot затем pause hold retire cancel даёт zero HTTP и zero claims', function (string $change): void {
    $f = new ProviderSendFixtures($this->server);
    DB::table('prc_network_budgets')->update(['tat' => DB::raw("clock_timestamp()+interval '2 seconds'")]);
    $changer = $f->worker('$until=hrtime(true)+10000000000; while (!\\Illuminate\\Support\\Facades\\DB::table("prc_network_permits")->exists()) { if(hrtime(true)>$until){throw new \\RuntimeException("reservation barrier timed out");} usleep(10000); } '.$change.' return ["changed"=>true];');
    $claim = $f->worker('$claim=$gate->claimSend($operation,$wire); if($claim->transport!==null){ $claim->transport->sendOnce($wire); } return ["denial"=>$claim->denial?->value];');
    $results = ConcurrentTransactions::concurrently(['send' => $claim, 'change' => $changer]);
    expect($results['change']['changed'])->toBeTrue()
        ->and($results['send']['denial'])->not->toBeNull()
        ->and($this->server->receipts())->toBe([])
        ->and(DB::table('llm_provider_calls')->whereNotNull('send_claimed_at')->count())->toBe(0)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0)
        ->and(DB::table('prc_network_permits')->whereNotNull('authorized_at')->count())->toBe(0)
        ->and(app(RequestRepository::class)->get($f->operation->physicalCallId)->outcome)->toBe('not_sent')
        ->and(DB::table('llm_budget_reservations')->sole()->settled_amount)->toBe('0.00000000');
})->with([
    'pause' => '\\Tests\\Support\\ProcessCore\\ExecutionFixtures::setMode("documents",\\App\\Enums\\Procurement\\Process\\ControlMode::Paused);',
    'provider pause' => '\\Tests\\Support\\ProcessCore\\ExecutionFixtures::setMode("provider:fixture-provider",\\App\\Enums\\Procurement\\Process\\ControlMode::Paused);',
    'handler hold' => '\\Tests\\Support\\ProcessCore\\ExecutionFixtures::holdHandler(\\App\\Enums\\Procurement\\Process\\StageCode::Screening);',
    'account hold' => 'app(\\App\\Repositories\\Llm\\Request\\FinancialReservationRepository::class)->configureAccount("fixture-account","USD",1,"0","fixture","hold while waiting");',
    'retire' => '\\Illuminate\\Support\\Facades\\DB::table("prc_source_targets")->where("id",$target->value)->update(["retired_at"=>now()]);',
    'cancel' => '\\Tests\\Support\\ProcessCore\\FinalizeFixtures::revoke($lease->workId,true);',
    'analysis head drift' => '\\Illuminate\\Support\\Facades\\DB::statement("UPDATE prc_document_versions SET analysis_generation=analysis_generation+1,current_analysis_run_id=NULL WHERE current_analysis_run_id=?",[$run->value]);',
]);

test('отказ второй network scope откатывает финансовую claim и сетевые request facts', function (): void {
    $f = new ProviderSendFixtures($this->server);
    DB::table('prc_network_budgets')->update(['tat' => DB::raw("clock_timestamp()+interval '1 second'")]);
    $changer = $f->worker('$until=hrtime(true)+10000000000; while (!\\Illuminate\\Support\\Facades\\DB::table("prc_network_permits")->exists()) { if(hrtime(true)>$until){throw new \\RuntimeException("reservation barrier timed out");} usleep(10000); } \\Illuminate\\Support\\Facades\\DB::table("prc_network_budgets")->where("scope_key","like","route-origin:%")->increment("policy_version"); return ["changed"=>true];');
    $result = ConcurrentTransactions::concurrently(['send' => $f->worker('$r=$gate->claimSend($operation,$wire); return ["granted"=>$r->transport!==null];'), 'change' => $changer]);
    expect($result['send']['granted'])->toBeFalse()
        ->and(DB::table('llm_provider_calls')->whereNotNull('send_claimed_at')->count())->toBe(0)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0)
        ->and($this->server->receipts())->toBe([]);
});

test('поздний permit покрывает полный total и cleanup от actual authorization', function (): void {
    $f = new ProviderSendFixtures($this->server);
    DB::table('prc_network_budgets')->update(['tat' => DB::raw("clock_timestamp()+interval '0.2 seconds'"),
        'send_tat' => DB::raw("clock_timestamp()+interval '1.5 seconds'")]);
    $result = $f->gate()->claimSend($f->operation, $f->wire);
    expect($result->transport)->not->toBeNull();
    $permit = DB::table('prc_network_permits')->sole();
    $coverage = (float) DB::selectOne('SELECT extract(epoch FROM (?::timestamptz-?::timestamptz)) AS seconds', [$permit->expires_at, $permit->authorized_at])->seconds;
    expect($coverage)->toBeGreaterThanOrEqual($f->profile->totalSeconds + 30)
        ->and((float) DB::selectOne('SELECT extract(epoch FROM (?::timestamptz-?::timestamptz)) AS seconds', [$permit->authorized_at, $permit->slot_at])->seconds)->toBeGreaterThan(1.0)
        ->and($result->transport->sendOnce($f->wire)->status)->toBe(200)
        ->and($this->server->receipts())->toHaveCount(1);
});

test('нет owning gate или wire fingerprint изменён: typed denial без network authorization и HTTP', function (): void {
    $f = new ProviderSendFixtures($this->server);
    expect(app(StructuredCallExecutor::class)->execute($f->operation, $f->wire, null)->denial)->toBe(ProviderSendDenial::Unsupported);
    $changed = new ProviderWireRequest($f->plan, $f->wire->method, $f->wire->endpoint, $f->wire->allowedOrigin, '{"tampered":true}');
    expect($f->gate()->claimSend($f->operation, $changed)->denial)->toBe(ProviderSendDenial::WireMismatch)
        ->and($this->server->receipts())->toBe([])
        ->and(DB::table('prc_network_demands')->count())->toBe(0)
        ->and(DB::table('llm_provider_calls')->whereNotNull('send_claimed_at')->count())->toBe(0);
    FinalizeFixtures::expire($f->work, 600);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);
    $lease = FinalizeFixtures::running($f->work);
    $period = (string) DB::table('llm_budget_periods')->value('id');
    $secondOp = app(AnalysisCallCoordinator::class)->authorize($lease, $f->run, $f->plan, $period, $f->wire);
    $deniedGate = ProviderSendFixtures::gateFor($lease, $f->run, $f->target, $f->route, $f->profile, new SafeUrl('https://denied.example.test/'));
    expect($deniedGate->claimSend($secondOp, $f->wire)->denial)->toBe(ProviderSendDenial::Network)
        ->and(app(RequestRepository::class)->get($secondOp->physicalCallId)->outcome)->toBe('not_sent')
        ->and(DB::table('llm_budget_reservations')->where('id', $secondOp->reservationId)->value('state'))->toBe('settled');
});

test('provider HTTP error и redirect выполняют один send без SDK retry или follow', function (int $status): void {
    $f = new ProviderSendFixtures($this->server);
    $path = '/v1beta/models/fixture-model:generateContent';
    $this->server->respond($path, '{"error":"fixture"}', $status, ['Location' => '/ok'], 'POST');
    // The server's first response is the original success; select the explicit error fixture
    // without using a client-supplied attempt number.
    $definitions = json_decode(file_get_contents($this->server->root.'/responses.json'), true, flags: JSON_THROW_ON_ERROR);
    array_shift($definitions['POST '.$path]);
    file_put_contents($this->server->root.'/responses.json', json_encode($definitions, JSON_THROW_ON_ERROR));
    $result = app(StructuredCallExecutor::class)->execute($f->operation, $f->wire, $f->gate());
    expect($result->status)->toBe($status)
        ->and($this->server->receipts())->toHaveCount(1)
        ->and($this->server->receipts()[0]['path'])->toBe($path)
        ->and(DB::table('prc_request_attempts')->count())->toBe(1)
        ->and(DB::table('prc_network_permits')->sole()->state)->toBe('released')
        ->and(app(RequestRepository::class)->get($f->operation->physicalCallId)->requestState)->toBe('unknown')
        ->and(DB::table('llm_budget_reservations')->sole()->state)->toBe('reserved');
})->with([302, 503]);

test('недоверенный TLS отвергается реальным cURL и сохраняет typed TLS unknown без HTTP receipt', function (): void {
    $f = new ProviderSendFixtures($this->server, tls: true);
    $result = app(StructuredCallExecutor::class)->execute($f->operation, $f->wire, $f->gate());
    expect($result->failure)->toBe(ProviderTransportFailure::Tls)
        ->and($this->server->receipts())->toBe([])
        ->and(DB::table('llm_provider_calls')->whereNotNull('send_claimed_at')->count())->toBe(1)
        ->and(app(RequestRepository::class)->get($f->operation->physicalCallId)->requestState)->toBe('unknown')
        ->and(DB::table('llm_budget_reservations')->sole()->state)->toBe('reserved')
        ->and(DB::table('prc_network_permits')->sole()->state)->toBe('released')
        ->and(DB::table('prc_route_target_health')->sole()->consecutive_failures)->toBe(0);
});

test('SIGKILL после joint claim до wire остаётся honest unknown и удерживает budget', function (): void {
    $f = new ProviderSendFixtures($this->server);
    expect(fn () => ConcurrentTransactions::execute($f->worker('$claim=$gate->claimSend($operation,$wire); if($claim->transport===null){throw new \\RuntimeException("claim failed");} posix_kill(getmypid(),SIGKILL); return [];')))->toThrow(RuntimeException::class);
    expect(DB::table('llm_provider_calls')->whereNotNull('send_claimed_at')->count())->toBe(1)
        ->and(DB::table('prc_request_attempts')->count())->toBe(1)
        ->and($this->server->receipts())->toBe([]);
    FinalizeFixtures::expire($f->work, 600);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1)
        ->and(ExecutionFixtures::work($f->work)->reason_code)->toBe('provider_result_unknown')
        ->and(DB::table('llm_budget_reservations')->sole()->state)->toBe('reserved')
        ->and(app(RequestRepository::class)->get($f->operation->physicalCallId)->requestState)->toBe('unknown')
        ->and($this->server->receipts())->toBe([]);
});
