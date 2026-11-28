<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Forward indexes for FinancialReservationRepository::assertSendEligible() unknown-count and active-exposure queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX llm_budget_reservations_period_state_idx ON llm_budget_reservations (period_id, state)');
        DB::statement("CREATE INDEX llm_provider_requests_unknown_state_idx ON llm_provider_requests (logical_request_id) WHERE state IN ('unknown', 'abandoned_unknown')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS llm_provider_requests_unknown_state_idx');
        DB::statement('DROP INDEX IF EXISTS llm_budget_reservations_period_state_idx');
    }
};
