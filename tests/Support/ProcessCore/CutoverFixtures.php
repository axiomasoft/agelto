<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Services\Procurement\Proxyma\Migration\Cutover;
use App\Services\Procurement\Proxyma\Migration\CutoverJournal;
use App\Services\Procurement\Proxyma\Migration\Inventory;
use Closure;
use Illuminate\Support\Facades\DB;

final class CutoverFixtures
{
    /** @return array{Cutover, string, array<string, mixed>, Inventory} */
    public static function prepare(?Closure $fault = null, bool $populated = true): array
    {
        ProxymaFixtures::clean();
        DB::statement('TRUNCATE prc_network_budgets CASCADE');
        DB::statement('TRUNCATE llm_provider_requests,llm_provider_calls,llm_budget_reservations,llm_budget_periods,llm_budget_accounts CASCADE');
        ProxymaUpgradeFixtures::package();
        $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname=current_schema()
            AND (tablename LIKE 'pxm\_%' OR tablename LIKE 'prc_proxyma\_%') ORDER BY tablename");
        $names = array_map(static fn ($row) => DB::connection()->getQueryGrammar()->wrap($row->tablename), $tables);
        DB::statement('TRUNCATE '.implode(', ', $names).' CASCADE');
        $f = $populated ? DB::transaction(fn () => ProxymaUpgradeFixtures::populate()) : ['map' => []];
        [$inventory, , , $id] = ProxymaUpgradeFixtures::services();

        return [new Cutover(new CutoverJournal($inventory), $fault), $id, $f, $inventory];
    }

    public static function snapshot(): array
    {
        $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname=current_schema()
            AND (tablename LIKE 'pxm\_%' OR tablename IN ('prc_network_budgets','prc_route_health','prc_route_target_health',
            'prc_target_health','prc_request_attempts','llm_provider_calls','llm_budget_reservations','prc_proxyma_state_archive')) ORDER BY tablename");
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table->tablename] = DB::table($table->tablename.' as s')->selectRaw('to_jsonb(s)::text AS payload')
                ->orderByRaw('to_jsonb(s)::text COLLATE "C"')->pluck('payload')->all();
        }

        return $snapshot;
    }
}
