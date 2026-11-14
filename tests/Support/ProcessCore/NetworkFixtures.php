<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Network\OutboundOperationHandle;
use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Platform\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Enums\Procurement\Network\ResponseKind;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Network\OutboundOperationRepository;
use App\Services\Procurement\Network\Contracts\SourceCatalogProvider;
use App\Services\Procurement\Network\SourceCatalogSync;
use Illuminate\Support\Facades\DB;

/** Initial machinery only. Raw demand/permit inserts exercise DDL, never simulate send readiness. */
final class NetworkFixtures implements SourceCatalogProvider
{
    public static function profile(): RequestProfile
    {
        return new RequestProfile('fixture.card', 5, 15, 30, 8_388_608, 25_165_824, 3, 3, 2, [200, 600], ResponseKind::Text, 'UTF-8', 1);
    }

    public function sourceTargets(): SourceTargetDefinitions
    {
        return self::catalog();
    }

    public static function catalog(string $code = 'fixture', ?OriginBudgetPolicy $budget = null): SourceTargetDefinitions
    {
        return new SourceTargetDefinitions($code, [
            new SourceTargetDefinition('card', 'detail', CanonicalOrigin::fromUrl('https://EXAMPLE.test/card'), [self::profile()], $budget),
            new SourceTargetDefinition('status', 'status_refresh', CanonicalOrigin::fromUrl('https://example.test:443/status'), [self::profile()], $budget),
        ]);
    }

    public static function platform(string $code = 'fixture'): Platform
    {
        return Platform::factory()->create(['code' => $code]);
    }

    public static function open(): OutboundOperationHandle
    {
        app(SourceCatalogSync::class)->sync(self::catalog());
        HistoricalNetworkFixtures::synchronize();

        return app(OutboundOperationRepository::class)->open(
            LedgerFixtures::uuid(), LedgerFixtures::uuid(), OutboundOperationKind::LegacyBridge,
            null, 'fixture.call', LedgerFixtures::hash('policy'), ['acquisition:platform:fixture'], 60, 120,
        );
    }

    /** @param array<string, mixed> $overrides */
    public static function demand(OutboundOperationHandle $owner, array $overrides = []): string
    {
        $id = LedgerFixtures::uuid()->value;
        DB::table('prc_network_demands')->insert([
            'id' => $id, 'physical_request_id' => LedgerFixtures::uuid()->value,
            'operation_id' => $owner->id->value, 'operation_generation' => $owner->generation,
            'origin_budget_id' => DB::table('prc_network_budgets')->where('scope_key', CanonicalOrigin::fromUrl('https://example.test')->budgetScopeKey())->sole()->id,
            'route_id' => DB::table('prc_network_routes')->sole()->id,
            'target_id' => DB::table('prc_source_targets')->where('target_key', 'card')->sole()->id,
            'traffic_class' => 'detail', 'purpose' => 'ordinary', 'state' => 'queued',
            'registered_at' => DB::raw('clock_timestamp()'), 'renewed_at' => DB::raw('clock_timestamp()'),
            'expires_at' => DB::raw("clock_timestamp() + interval '5 seconds'"),
            'operation_deadline' => $owner->deadlineAt, ...$overrides,
        ]);

        return $id;
    }

    public static function permit(string $demand): string
    {
        $id = LedgerFixtures::uuid()->value;
        DB::table('prc_network_permits')->insert([
            'id' => $id, 'demand_id' => $demand, 'state' => 'reserved',
            'slot_at' => DB::raw('clock_timestamp()'),
            'expires_at' => DB::raw("clock_timestamp() + interval '60 seconds'"),
            'created_at' => DB::raw('clock_timestamp()'),
            'policy_versions' => '{}', 'policy_schema_version' => 1,
            'health_epochs' => '{}', 'health_epochs_schema_version' => 1,
        ]);

        return $id;
    }
}
