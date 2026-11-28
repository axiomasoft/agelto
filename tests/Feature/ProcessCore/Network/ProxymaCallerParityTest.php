<?php

declare(strict_types=1);

use App\Dto\Llm\Execution\LlmOptions;
use App\Dto\Llm\Prompt\LlmPrompt;
use App\Dto\Procurement\Acquisition\Listing\ListingFetched;
use App\Dto\Procurement\Network\Control\ProviderKey;
use App\Dto\Procurement\Network\Control\ProviderTargetDefinitions;
use App\Dto\Procurement\Network\Http\HttpRequestPayload;
use App\Dto\Procurement\Network\Http\TransferBudget;
use App\Dto\Procurement\Network\Outcome\TransportBlocked;
use App\Dto\Procurement\Network\Outcome\TransportDeferred;
use App\Dto\Procurement\Network\Outcome\TransportSucceeded;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinition;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinitions;
use App\Exceptions\Llm\UnsupportedConsumer;
use App\Models\Llm\Provider;
use App\Repositories\Procurement\Network\SourceCatalogRepository;
use App\Services\Llm\Providers\CatalogOnlyProvider;
use App\Services\Platforms\Eis\Filters\Okpd2NodeSynchronizer;
use App\Services\Procurement\Network\NetworkProbeReconcileCategory;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Proxyma\Caller\CallerOutbound;
use App\Services\Procurement\Proxyma\Caller\CallerSendFacts;
use App\Services\Procurement\Proxyma\Caller\TelegramOutbound;
use Illuminate\Support\Facades\DB;
use Proxyma\Infrastructure\Database\PackageSchema;
use Tests\Support\Platforms\Eis\EisCsvFixture;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;
use Tests\Support\ProcessCore\Http\AcquisitionWireFixture;
use Tests\Support\ProcessCore\Http\CallerFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\TransportFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\ProxymaCallerFixtures;
use Tests\Support\ProcessCore\ProxymaFixtures;
use Tests\Support\ProcessCore\RawEvidenceFixtures;

beforeEach(function (): void {
    ProxymaFixtures::clean();
    $this->raw = new RawEvidenceFixtures;
});
afterEach(function (): void {
    ProxymaFixtures::clean();
    $this->raw->clean();
});

test('package CSV caller сохраняет win1251 partial и не публикует неполную строку', function (): void {
    $wire = new WireFixture;
    try {
        $fixture = new AcquisitionWireFixture($wire);
        ProxymaCallerFixtures::install($wire);
        $wire->respond('/csv', EisCsvFixture::body(), headers: ['Content-Type' => 'text/csv; charset=windows-1251']);
        $result = $fixture->fetch($fixture->listingRequest('2026-08-05'));
        expect($result->value)->toBeInstanceOf(ListingFetched::class)
            ->and($result->value->page->rowsTotal)->toBe(4)
            ->and($result->value->page->assessment->completeness->value)->toBe('partial')
            ->and($result->value->page->summaries->items)->toBeEmpty()
            ->and($result->requestsUsed)->toBe(1)
            ->and(DB::table('prc_procurements')->count())->toBe(0)
            ->and($wire->receipts())->toHaveCount(1)
            ->and(DB::table('prc_request_attempts')->count())->toBe(0)
            ->and(DB::table(PackageSchema::tables()['attempts'])->where('status', 'completed')->count())->toBe(1);
    } finally {
        $wire->stop();
    }
});

test('package ОКПД2 caller сохраняет конечный обход и пишет один physical fact', function (): void {
    $wire = new WireFixture;
    try {
        CallerFixtures::platform($wire);
        ProxymaCallerFixtures::install($wire);
        $wire->respond('/children', '[]', headers: ['Content-Type' => 'application/json']);
        $result = app(Okpd2NodeSynchronizer::class)->run(dryRun: true, maxRequests: 1);
        expect($result['requests'])->toBe(1)->and($result['nodes_seen'])->toBe(0)->and($result['written'])->toBeFalse()
            ->and($wire->receipts())->toHaveCount(1)
            ->and(DB::table('prc_request_attempts')->count())->toBe(0)
            ->and(DB::table(PackageSchema::tables()['attempts'])->count())->toBe(1);
    } finally {
        $wire->stop();
    }
});

test('package caller сохраняет terminal HTTP evidence и общий send budget', function (): void {
    $wire = new WireFixture;
    try {
        $f = new PermitFixtures($wire);
        ProxymaCallerFixtures::install($wire);
        $context = $f->context();
        DB::table('prc_outbound_operations')->where('id', $context->lease()->id->value)
            ->update(['platform_id' => DB::table('prc_source_targets')->where('id', $f->target->value)->value('platform_id')]);
        $wire->respond('/ok', 'temporarily unavailable', status: 503);
        $result = app(CallerOutbound::class)->execute($f->plan(), $context,
            ProxymaCallerFixtures::gate($f->route->routeId->value), $wire->policy(), new TransferBudget(262144, 262144));
        expect($result)->toBeInstanceOf(TransportDeferred::class)
            ->and($result->finalResponse?->status)->toBe(503)
            ->and($result->finalResponse?->body->utf8)->toBe('temporarily unavailable')
            ->and($wire->receipts())->toHaveCount(3)
            ->and(DB::table('prc_request_attempts')->count())->toBe(0)
            ->and(DB::table(PackageSchema::tables()['attempts'])->count())->toBe(3);
        expect(CallerSendFacts::completed($result->lastRequestId->value, 'outbound_operation',
            $context->lease()->id->value, $context->lease()->generation + 1))->toBeFalse();
    } finally {
        $wire->stop();
    }
});

test('deferred owner отменяет только свой ticket без permit и следующему owner не оставляет FIFO head', function (): void {
    $wire = new WireFixture;
    try {
        $f = new PermitFixtures($wire);
        ProxymaCallerFixtures::install($wire);
        $tables = PackageSchema::tables();
        DB::table($tables['budgets'])->update([
            'tat' => DB::raw("clock_timestamp() + interval '2500 milliseconds'"),
            'send_tat' => DB::raw("clock_timestamp() + interval '2500 milliseconds'"),
        ]);
        $accounting = static fn (): array => DB::table($tables['budgets'].' as budget')
            ->leftJoin($tables['quota_windows'].' as quota', 'quota.budget_id', '=', 'budget.id')
            ->select(['budget.id', 'budget.tat', 'budget.send_tat', 'budget.inflight', DB::raw('COALESCE(quota.reserved, 0) AS reserved'), DB::raw('COALESCE(quota.spent, 0) AS spent')])
            ->orderBy('budget.id')->get()->map(static fn (object $row): array => (array) $row)->all();
        $before = $accounting();
        $first = $f->context();
        DB::table('prc_outbound_operations')->where('id', $first->lease()->id->value)
            ->update(['platform_id' => DB::table('prc_source_targets')->where('id', $f->target->value)->value('platform_id')]);
        $firstPlan = $f->plan();
        $deferred = app(CallerOutbound::class)->execute($firstPlan, $first, $f->gate(), $wire->policy(), new TransferBudget(262144, 262144));
        $firstDemand = DB::table($tables['demands'])->where('operation_id', $firstPlan->physicalRequestId->value)
            ->where('physical_attempt_id', $firstPlan->physicalRequestId->value)->sole();

        expect($deferred)->toBeInstanceOf(TransportDeferred::class)
            ->and($firstDemand->state)->toBe('cancelled')
            ->and(DB::table($tables['permits'])->where('operation_id', $firstPlan->physicalRequestId->value)->count())->toBe(0)
            ->and(DB::table($tables['attempts'])->where('operation_id', $firstPlan->physicalRequestId->value)->count())->toBe(0)
            ->and($wire->receipts())->toBeEmpty()
            ->and($accounting())->toBe($before);

        $waitDeadline = hrtime(true) + 5_000_000_000;
        while (new DateTimeImmutable((string) DB::selectOne('SELECT clock_timestamp() AS now')->now) < $deferred->retryAt->value) {
            if (hrtime(true) >= $waitDeadline) {
                throw new RuntimeException('Deferred retry did not become due in the bounded fixture window.');
            }
            usleep(10_000);
        }
        $wire->respond('/ok', 'next owner');
        $second = $f->context();
        DB::table('prc_outbound_operations')->where('id', $second->lease()->id->value)
            ->update(['platform_id' => DB::table('prc_source_targets')->where('id', $f->target->value)->value('platform_id')]);
        $secondPlan = $f->plan();

        expect($secondPlan->physicalRequestId->value)->not->toBe($firstPlan->physicalRequestId->value)
            ->and(app(CallerOutbound::class)->execute($secondPlan, $second, $f->gate(), $wire->policy(), new TransferBudget(262144, 262144)))
            ->toBeInstanceOf(TransportSucceeded::class)
            ->and($wire->receipts())->toHaveCount(1)
            ->and(DB::table($tables['attempts'])->where('status', 'completed')->count())->toBe(1)
            ->and(new DateTimeImmutable((string) DB::selectOne('SELECT clock_timestamp() AS now')->now))->toBeLessThan(new DateTimeImmutable($firstDemand->expires_at));
    } finally {
        $wire->stop();
    }
});

test('устаревшее health evidence до авторизации откладывает read-only caller и заново валидирует следующую работу', function (): void {
    $wire = new WireFixture;
    try {
        $f = new PermitFixtures($wire);
        ProxymaCallerFixtures::installAcceptanceProfile($wire);
        $tables = PackageSchema::tables();
        $contextA = $f->context();
        DB::table('prc_outbound_operations')->where('id', $contextA->lease()->id->value)
            ->update(['platform_id' => DB::table('prc_source_targets')->where('id', $f->target->value)->value('platform_id')]);
        $planA = $f->plan();
        [, $reserved] = ProxymaCallerFixtures::reserve($planA, $contextA);

        ProxymaCallerFixtures::configure();
        expect(DB::transactionLevel())->toBe(0);
        $wire->respond('/ok', 'concurrent receipt');
        $wire->respond('/ok', 'fresh revalidation');
        $contextB = $f->context();
        DB::table('prc_outbound_operations')->where('id', $contextB->lease()->id->value)
            ->update(['platform_id' => DB::table('prc_source_targets')->where('id', $f->target->value)->value('platform_id')]);
        $advanced = app(CallerOutbound::class)->execute($f->plan(), $contextB, $f->gate(), $wire->policy(), new TransferBudget(262144, 262144));
        $result = app(CallerOutbound::class)->execute($planA, $contextA, $f->gate(), $wire->policy(), new TransferBudget(262144, 262144));

        expect($advanced)->toBeInstanceOf(TransportSucceeded::class)
            ->and($result)->toBeInstanceOf(TransportDeferred::class)
            ->and($result->reason->value)->toBe('dependency_unavailable')
            ->and($wire->receipts())->toHaveCount(1);

        $permit = DB::table($tables['permits'])->where('id', $reserved->permitId->value)->sole();
        expect(DB::table($tables['operations'])->where('id', $planA->physicalRequestId->value)->value('authorized_attempts'))->toBe(0)
            ->and($permit->state)->toBe('cancelled_before_send')->and($permit->authorized_at)->toBeNull()->and($permit->claim_id)->toBeNull()
            ->and(DB::table($tables['attempts'])->where('operation_id', $planA->physicalRequestId->value)->count())->toBe(0)
            ->and(DB::table($tables['demands'])->where('id', $permit->demand_id)->value('state'))->toBe('cancelled')
            ->and(DB::table($tables['reservations'])->where('permit_id', $permit->id)->where('state', 'released')->count())->toBeGreaterThan(0)
            ->and(DB::table($tables['reservations'])->where('permit_id', $permit->id)->where('state', '!=', 'released')->count())->toBe(0)
            ->and(DB::table($tables['quota_reservations'])->where('permit_id', $permit->id)->where('state', '!=', 'released')->count())->toBe(0);

        usleep(1_050_000);
        expect(new DateTimeImmutable((string) DB::selectOne('SELECT clock_timestamp() AS now')->now))->toBeGreaterThanOrEqual($result->retryAt->value);

        $freshContext = $f->context();
        DB::table('prc_outbound_operations')->where('id', $freshContext->lease()->id->value)
            ->update(['platform_id' => DB::table('prc_source_targets')->where('id', $f->target->value)->value('platform_id')]);
        $freshPlan = $f->plan();
        $fresh = app(CallerOutbound::class)->execute($freshPlan, $freshContext, $f->gate(), $wire->policy(), new TransferBudget(262144, 262144));

        expect($fresh)->toBeInstanceOf(TransportSucceeded::class)->and($wire->receipts())->toHaveCount(2)
            ->and(DB::table($tables['attempts'])->where('operation_id', $freshPlan->physicalRequestId->value)->where('status', 'completed')->count())->toBe(1);
    } finally {
        $wire->stop();
    }
});

test('смена generation между подготовкой и claim запрещает socket и второй engine', function (): void {
    $wire = new WireFixture;
    try {
        $f = new PermitFixtures($wire);
        ProxymaCallerFixtures::install($wire);
        $context = $f->context();
        $changed = false;
        DB::table('prc_outbound_operations')->where('id', $context->lease()->id->value)
            ->update(['platform_id' => DB::table('prc_source_targets')->where('id', $f->target->value)->value('platform_id')]);
        DB::listen(function ($query) use (&$changed): void {
            if (! $changed && str_contains($query->sql, 'pxm_demands') && str_starts_with(strtolower($query->sql), 'insert')) {
                $changed = true;
                DB::table('prc_network_authorities')->where('id', 'network')->increment('generation');
            }
        });
        $result = app(CallerOutbound::class)->execute($f->plan(), $context, $f->gate(), $wire->policy(), new TransferBudget(262144, 262144));
        expect($result)->toBeInstanceOf(TransportBlocked::class)
            ->and($changed)->toBeTrue()
            ->and($wire->receipts())->toBeEmpty()->and(DB::table('prc_request_attempts')->count())->toBe(0);
    } finally {
        $wire->stop();
    }
});

test('package path не даёт OpenAI caller присвоить provider без financial owner', function (): void {
    $wire = new WireFixture;
    try {
        CallerFixtures::provider($wire);
        Provider::factory()->create(['code' => 'wire', 'is_active' => true]);
        ProxymaCallerFixtures::install($wire);
        expect(fn () => app(CatalogOnlyProvider::class)->complete(
            new LlmPrompt(systemPart: 'fixture'),
            new LlmOptions('wire', 'wire-model', 0.0, 32, baseUrl: $wire->url('/v1')->reveal())))
            ->toThrow(UnsupportedConsumer::class);
        expect($wire->receipts())->toBeEmpty()->and(DB::table(PackageSchema::tables()['attempts'])->count())->toBe(0);
    } finally {
        $wire->stop();
    }
});

test('notification POST имеет отдельного owner и не повторяется после terminal HTTP error', function (): void {
    $wire = new WireFixture;
    try {
        new PermitFixtures($wire);
        $profile = TransportFixtures::profile([
            'code' => 'notification.telegram', 'attempts' => 1, 'distinct_routes' => 1, 'delays_ms' => [], 'redirects' => 0,
        ]);
        app(SourceCatalogSync::class)->syncProvider(new ProviderTargetDefinitions(
            new ProviderKey('telegram'), [new SourceTargetDefinition(
                'provider:telegram:notification', 'notification', $wire->url('/notify')->origin, [$profile],
            )]));
        HistoricalNetworkFixtures::synchronize();
        ProxymaCallerFixtures::install($wire);
        $wire->respond('/notify', 'retry later', status: 503, method: 'POST');
        $wire->respond('/notify', '{"ok":true}', method: 'POST');
        $outbound = app()->makeWith(TelegramOutbound::class, ['urls' => $wire->policy()]);
        $payload = new HttpRequestPayload(['content-type' => 'application/json'], '{"text":"fixture"}');
        expect($outbound->send($wire->url('/notify'), $payload))->toBeFalse()
            ->and($wire->receipts())->toHaveCount(1)
            ->and($outbound->send($wire->url('/notify'), $payload))->toBeTrue()
            ->and($wire->receipts())->toHaveCount(2)
            ->and(DB::table('prc_outbound_operations')->where('caller_code', 'notification.telegram')->where('state', 'closed')->count())->toBe(2)
            ->and(DB::table('prc_request_attempts')->count())->toBe(0);
    } finally {
        $wire->stop();
    }
});

test('package redirect cycle конечен и каждый hop имеет собственный permit', function (): void {
    $wire = new WireFixture;
    try {
        $f = new PermitFixtures($wire);
        ProxymaCallerFixtures::install($wire);
        $context = $f->context();
        DB::table('prc_outbound_operations')->where('id', $context->lease()->id->value)
            ->update(['platform_id' => DB::table('prc_source_targets')->where('id', $f->target->value)->value('platform_id')]);
        $wire->respond('/ok', '', status: 302, headers: ['Location' => '/cycle']);
        $wire->respond('/cycle', '', status: 302, headers: ['Location' => '/ok']);
        $result = app(CallerOutbound::class)->execute($f->plan(), $context,
            ProxymaCallerFixtures::gate($f->route->routeId->value), $wire->policy(), new TransferBudget(262144, 262144));
        expect($result)->toBeInstanceOf(TransportBlocked::class)
            ->and($wire->receipts())->toHaveCount(2)
            ->and(DB::table(PackageSchema::tables()['attempts'])->count())->toBe(2);
    } finally {
        $wire->stop();
    }
});

test('объявленный package probe восстанавливает due target без записи legacy health', function (): void {
    $wire = new WireFixture;
    try {
        $f = new PermitFixtures($wire);
        $profile = TransportFixtures::profile([
            'code' => 'network.probe', 'attempts' => 1, 'distinct_routes' => 1, 'delays_ms' => [],
        ]);
        app(SourceCatalogSync::class)->sync(new SourceTargetDefinitions($f->code, [
            new SourceTargetDefinition('card', 'detail', $f->url->origin, [$f->profile, $profile], probeUrl: $f->url->reveal()),
        ]));
        HistoricalNetworkFixtures::synchronize();
        ProxymaCallerFixtures::install($wire);
        DB::table(PackageSchema::tables()['target_health'])->where('target_id', $f->target->value)
            ->update(['state' => 'open', 'open_until' => DB::raw("clock_timestamp() - interval '1 second'"), 'availability' => 'unavailable']);
        $before = DB::table('prc_target_health')->orderBy('id')->get()->all();
        $wire->respond('/ok', 'healthy');
        expect(app(SourceCatalogRepository::class)->probeCandidates(null))->not->toBeEmpty();
        expect(app(NetworkProbeReconcileCategory::class)->reconcile())->toBe(1)
            ->and($wire->receipts())->toHaveCount(1)
            ->and(DB::table('prc_target_health')->orderBy('id')->get()->all())->toEqual($before)
            ->and(DB::table(PackageSchema::tables()['attempts'])->count())->toBe(1);
    } finally {
        $wire->stop();
    }
});
