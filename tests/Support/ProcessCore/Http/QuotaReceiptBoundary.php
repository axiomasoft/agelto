<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore\Http;

use App\Dto\Procurement\Network\AuthorizedHttpRequest;
use App\Dto\Procurement\Network\HttpRequestPayload;
use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Network\NetworkSendClaim;
use App\Dto\Procurement\Network\ScheduledNetworkPermit;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Repositories\Procurement\Network\NetworkPermitRepository;
use App\Services\Procurement\Network\HttpTransport;
use App\Services\Procurement\Network\UrlPolicy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestEnvironmentGuard;

/** Real child worker, stopped by its parent at the receipt transaction's commit boundary. */
final class QuotaReceiptBoundary
{
    /** @param array<string, mixed> $boundary */
    public static function evidence(string $case, WireFixture $wire, TransportDeferred $decision, array $boundary = []): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $case) !== 1) {
            throw new \InvalidArgumentException('Invalid quota evidence case.');
        }
        $root = storage_path('app/testing/process-core/quota-evidence/'.getenv('PROCESS_CORE_PROCESS'));
        if (! is_dir($root)) {
            mkdir($root, 0755, true);
        }
        file_put_contents($root.'/'.$case.'.json', json_encode([
            'case' => $case, 'retry_at' => $decision->retryAt->value->format('Y-m-d H:i:s.uP'), 'boundary' => $boundary,
            'server_receipts' => array_map(static fn (array $receipt): array => array_intersect_key($receipt, array_flip(['kind', 'path', 'ordinal'])), $wire->receipts()),
            'requests' => DB::table('prc_request_attempts')->get(['request_id', 'status', 'outcome', 'sent_at', 'finished_at'])->all(),
            'permits' => DB::table('prc_network_permits')->get(['id', 'state', 'authorized_at', 'expires_at'])->all(),
            'budgets' => DB::table('prc_network_budgets')->get(['scope_key', 'not_before', 'tat', 'send_tat'])->all(),
            'observations' => DB::table('prc_observations')->get(['observation_id', 'reason_code', 'stale', 'details'])->all(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public static function run(string $ticketId, string $url, string $marker, string $boundary): never
    {
        TestEnvironmentGuard::assertBootstrapTestDatabase(app());
        TestEnvironmentGuard::assertIsolatedTestFilesystem(app());
        $ticket = PermitFixtures::importTicket($ticketId, $url);
        $demand = DB::table('prc_network_demands')->where('id', $ticketId)->sole();
        $row = DB::table('prc_network_permits')->where('demand_id', $ticketId)->sole();
        $context = PermitFixtures::importContext($demand->operation_id);
        $repo = app(NetworkPermitRepository::class);
        $permit = new ScheduledNetworkPermit(new ProcessUuid($row->id), $ticket, new \DateTimeImmutable($row->slot_at), new \DateTimeImmutable($row->expires_at));
        $claim = $repo->claimSend($permit, $context);
        if (! $claim instanceof NetworkSendClaim || ! $claim->claimed) {
            throw new \RuntimeException('Quota boundary worker did not claim its real permit.');
        }
        $policy = new UrlPolicy([$ticket->plan->url->origin], ['fixture.test' => ['127.0.0.1']]);
        $result = app(HttpTransport::class)->sendOnce(new AuthorizedHttpRequest($ticket->plan, new HttpRouteBinding($ticket->route->routeId)),
            $policy->resolve($ticket->plan->url, $context, 1), new HttpRequestPayload, $context, new TransferBudget(1_000_000, 1_000_000));
        if ($result->head?->status !== 429) {
            throw new \RuntimeException('Quota boundary worker did not receive actual HTTP 429.');
        }
        // Test-owned immutable copy permits exact record retry after killing this worker.
        file_put_contents($marker.'.result', serialize($result));
        if ($boundary === 'before_commit') {
            DB::listen(static function (QueryExecuted $query) use ($marker): void {
                if (str_contains($query->sql, "UPDATE prc_network_permits SET state = 'released'")) {
                    self::pause($marker);
                }
            });
        }
        $quota = $repo->record($ticket->plan->physicalRequestId, $result);
        if (! $quota instanceof TransportDeferred) {
            throw new \RuntimeException('Quota receipt did not return its durable decision.');
        }
        self::pause($marker);
    }

    private static function pause(string $marker): never
    {
        file_put_contents($marker, json_encode(['transaction_level' => DB::transactionLevel(),
            'backend_pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid], JSON_THROW_ON_ERROR));
        $until = hrtime(true) + 10_000_000_000;
        while (hrtime(true) < $until) {
            usleep(10_000);
        }
        throw new \RuntimeException('Parent failed to kill quota boundary worker.');
    }
}
