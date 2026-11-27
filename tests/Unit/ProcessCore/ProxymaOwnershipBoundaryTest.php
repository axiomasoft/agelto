<?php

declare(strict_types=1);

use App\Services\Procurement\Proxyma\Caller\CallerExecutionPort;
use App\Services\Procurement\Proxyma\Caller\CallerPolicyResolver;
use Proxyma\Application\Execution\FiniteOutboundExecutor;
use Proxyma\Application\Execution\OutboundExecutor;
use Proxyma\Application\Execution\OutcomePolicyRegistry;
use Proxyma\Contracts\FiniteExecutionPort;
use Proxyma\Contracts\PolicyResolver;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Execution as X;
use Proxyma\Domain\Outcomes\AttemptFacts;
use Tests\Support\Architecture\ProxymaOwnershipRules as Rules;

test('production tree не содержит старых engine классов и нарушений ownership', function (): void {
    foreach (Rules::RETIRED as $path) {
        expect(is_file(app_path($path.'.php')))->toBeFalse($path);
    }
    $violations = [];
    foreach (['app', 'database/migrations', 'database/factories', 'database/seeders'] as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = substr($file->getPathname(), strlen(base_path()) + 1);
            foreach (Rules::violations($path, file_get_contents($file->getPathname())) as $violation) {
                $violations[] = $path.':'.$violation;
            }
        }
    }
    expect($violations)->toBe([]);
});

test('исторический Proxy остаётся только носителем таблицы без factory casts и relations', function (): void {
    $class = 'App\\Models\\Procurement\\Crawler\\Proxy';
    $model = new $class;
    $reflection = new ReflectionClass($class);
    $own = array_filter($reflection->getMethods(), static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class);
    expect($model->getTable())->toBe('prc_proxies')->and($own)->toBe([])->and($reflection->getTraitNames())->toBe([]);
});

test('history exception не разрешает SQL или builder запись в архив', function (string $source, string $violation): void {
    expect(Rules::violations('app/Services/Procurement/Proxyma/Migration/HistoricalRequests.php', '<?php '.$source))->toContain($violation);
})->with([
    ['DB::statement("UPDATE prc_network_permits SET state=\'sent\'");', 'legacy_sql_write'],
    ['DB::table("prc_request_attempts")->where("request_id", $id)->delete();', 'legacy_builder_write'],
    ['DB::table("prc_proxies")->insert(["host" => "bad"]);', 'legacy_builder_write'],
    ['$this->db->table("prc_network_routes")->update(["enabled" => true]);', 'legacy_builder_write'],
]);

test('новый runtime caller не получает history или direct transport exception', function (): void {
    expect(Rules::violations('app/Services/Procurement/NewCaller.php', '<?php DB::table("prc_network_permits")->count();'))->toContain('unowned_legacy_table')
        ->and(Rules::violations('app/Services/Procurement/NewCaller.php', '<?php use Illuminate\\Support\\Facades\\Http; Http::get("https://example.test");'))->toContain('direct_transport')
        ->and(Rules::violations('app/NewCaller.php', '<?php use App\\Models\\Procurement\\Crawler\\Proxy;'))->toContain('proxy_runtime_reference');
    expect(Rules::violations('app/NewCaller.php', '<?php use App\\Models\\Procurement\\Crawler\\{Proxy};'))->toContain('proxy_runtime_reference')
        ->and(Rules::violations('app/NewCaller.php', '<?php use App\\Models\\Procurement\\Proxyma\\{LegacyProxy};'))->toContain('carrier_runtime_reference');
});

test('consumer ports реализуют native interfaces без runtime compatibility alias', function (): void {
    expect(is_subclass_of(CallerExecutionPort::class, FiniteExecutionPort::class))->toBeTrue()
        ->and(is_subclass_of(CallerPolicyResolver::class, PolicyResolver::class))->toBeTrue()
        ->and(method_exists(OutboundExecutor::class, 'executeAttempt'))->toBeTrue();
});

test('согласованное transport exception разрешает cURL только в точном consumer классе', function (): void {
    $path = 'app/Services/Procurement/Proxyma/Transport/AgeltoPhysicalHttpTransport.php';
    expect(Rules::violations($path, '<?php curl_init();'))->not->toContain('direct_transport')
        ->and(Rules::violations('app/Services/Procurement/Proxyma/OtherTransport.php', '<?php curl_init();'))->toContain('direct_transport')
        ->and(Rules::violations($path, '<?php dns_get_record("fixture.test");'))->toContain('direct_transport')
        ->and(Rules::violations($path, '<?php DB::table("prc_network_permits")->update(["state" => "sent"]);'))->toContain('legacy_builder_write');
});

test('pinned migrations остаются побайтно неизменными после удаления runtime', function (string $path, string $sha256): void {
    expect(hash_file('sha256', base_path($path)))->toBe($sha256);
})->with([
    ['database/migrations/2026_07_04_100040_create_prc_proxies_table.php', 'f5e896855b0882512824898e8282025ae83822dd1b282ab5a70768516585d101'],
    ['database/migrations/2026_10_25_100000_create_prc_process_network_tables.php', 'b5bf4161db580aef12fcbf9f5b9247f515c835aa38ebde7413629a0ae464d35d'],
    ['database/migrations/2026_10_25_100001_create_prc_request_observation_tables.php', '23afbc7dc57d25af92a3b11ce1e5a9320a5484b97018d8142bb0f061e43e5b5d'],
]);

function finiteReplayRequest(int $attempts = 3, int $redirects = 2, ?int $limit = null): X\OutboundRequest
{
    return new X\OutboundRequest(X\Uuid::random(), X\Uuid::random(), 'GET', new X\SafeUrl('https://one.test/start'),
        new X\RequestTimeoutPolicy(100, 100, 500, 0, $attempts, min(2, $attempts), $redirects, [0, 0]),
        headers: new X\PreparedHeaders(['authorization' => 'private-fixture', 'x-safe' => 'keep']), sendLimit: $limit);
}

function finiteReplayPort(array $responses, bool $mapped = true, ?X\RequestTimeoutPolicy $effective = null): FiniteExecutionPort
{
    return new class($responses, $mapped, $effective) implements FiniteExecutionPort
    {
        public array $requests = [];

        public bool $available = true;

        public X\Uuid $mappedTarget;

        private X\Uuid $route;

        public function __construct(private array $responses, private bool $mapped, private ?X\RequestTimeoutPolicy $effective)
        {
            $this->mappedTarget = X\Uuid::random();
            $this->route = X\Uuid::random();
        }

        public function executeAttempt(X\OutboundRequest $request, X\RouteSelectionContext $routes, int $attempt, int $redirects): X\PhysicalExecutionResult|X\ExecutionResult
        {
            $this->requests[] = $request;
            [$status, $headers] = array_shift($this->responses) ?? throw new LogicException('Unexpected extra physical send.');
            $result = $status instanceof X\TransportFailure ? X\AttemptResult::failed($status)
                : X\AttemptResult::delivered(new X\ResponseDescriptor($status, $headers, new X\RawResponseBody('fixture'), 7, 7));
            $wire = new X\WireRequest($request->operationId, $request->operationId, $request->targetId, 1, $request->method,
                $request->url, $request->timeouts, $this->now()->modify('+1 hour'), $request->headers, $request->body, $request->idempotency);
            $outcome = (new OutcomePolicyRegistry)->evaluate('default', AttemptFacts::fromAttempt($wire, $result, $this->route,
                RouteKind::Direct, null, 'fixture:nat', null, $attempt, $redirects), $result, $wire, $this->now());

            return new X\PhysicalExecutionResult($request->operationId, $this->route, $result, $outcome, $this->effective ?? $request->timeouts);
        }

        public function redirectTarget(X\Uuid $from, X\SafeUrl $destination): ?X\Uuid
        {
            return $this->mapped ? $this->mappedTarget : null;
        }

        public function canStart(X\Duration $required): bool
        {
            return $this->available;
        }

        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-11-11T12:00:00+00:00');
        }
    };
}

test('core redirect использует новый mapped target и отдельную physical operation без credential forwarding', function (): void {
    $request = finiteReplayRequest();
    $port = finiteReplayPort([[302, ['location' => ['https://two.test/next']]], [200, []]]);
    $result = (new FiniteOutboundExecutor)->execute($request, $port);
    expect($result)->toBeInstanceOf(X\ExecutionSucceeded::class)->and($port->requests)->toHaveCount(2)
        ->and($port->requests[0]->targetId->value)->toBe($request->targetId->value)
        ->and($port->requests[1]->targetId->value)->toBe($port->mappedTarget->value)
        ->and($port->requests[1]->operationId->value)->not->toBe($request->operationId->value)
        ->and($port->requests[1]->headers->reveal())->not->toHaveKey('authorization');
});

test('core запрещает missing mapping downgrade и redirect cycle до следующего send', function (array $responses, bool $mapped, int $sends): void {
    $port = finiteReplayPort($responses, $mapped);
    $result = (new FiniteOutboundExecutor)->execute(finiteReplayRequest(), $port);
    expect($result)->toBeInstanceOf(X\ExecutionBlocked::class)->and($result->reason)->toBe(X\BlockedReason::UrlPolicyDenied)
        ->and($port->requests)->toHaveCount($sends);
})->with([
    [[[302, ['location' => ['https://two.test/next']]]], false, 1],
    [[[302, ['location' => ['http://two.test/next']]]], true, 1],
    [[[302, ['location' => ['/next']]], [302, ['location' => ['/start']]]], true, 2],
]);

test('effective retry ceiling и общий send limit не дают лишней физической отправки', function (): void {
    $port = finiteReplayPort([[503, []]], effective: new X\RequestTimeoutPolicy(100, 100, 500, 0, 1));
    $result = (new FiniteOutboundExecutor)->execute(finiteReplayRequest(), $port);
    expect($result)->toBeInstanceOf(X\ExecutionDeferred::class)->and($port->requests)->toHaveCount(1)
        ->and($result->lastDeliveredResponse?->statusCode)->toBe(503);
    $port = finiteReplayPort([[302, ['location' => ['/next']]]]);
    expect((new FiniteOutboundExecutor)->execute(finiteReplayRequest(limit: 1), $port))->toBeInstanceOf(X\ExecutionBlocked::class)
        ->and($port->requests)->toHaveCount(1);
});

test('finite port сохраняет retry delay и не выходит за slice перед первым physical send', function (): void {
    $delays = [];
    $port = finiteReplayPort([[503, []], [200, []]], effective: new X\RequestTimeoutPolicy(100, 100, 500, 0, 2, 1, 0, [20]));
    $executor = new FiniteOutboundExecutor(static function (int $microseconds) use (&$delays): void {
        $delays[] = $microseconds;
    });
    expect($executor->execute(finiteReplayRequest(), $port))->toBeInstanceOf(X\ExecutionSucceeded::class)
        ->and($port->requests)->toHaveCount(2)->and($delays)->toBe([20000]);
    $port = finiteReplayPort([]);
    $port->available = false;
    expect($executor->execute(finiteReplayRequest(), $port))->toBeInstanceOf(X\ExecutionDeferred::class)
        ->and($port->requests)->toBe([]);
});

test('неоднозначный POST не повторяется после transport failure и не следует redirect', function (): void {
    $read = finiteReplayRequest();
    $request = new X\OutboundRequest($read->operationId, $read->targetId, 'POST', $read->url, $read->timeouts,
        X\IdempotencyClass::AmbiguousWrite, body: X\WireBody::fromString('fixture'));
    $port = finiteReplayPort([[X\TransportFailure::Timeout, []]]);
    expect((new FiniteOutboundExecutor)->execute($request, $port))->not->toBeInstanceOf(X\ExecutionSucceeded::class)
        ->and($port->requests)->toHaveCount(1);
    $port = finiteReplayPort([[302, ['location' => ['/next']]]]);
    expect((new FiniteOutboundExecutor)->execute($request, $port))->toBeInstanceOf(X\ExecutionBlocked::class)
        ->and($port->requests)->toHaveCount(1);
});
