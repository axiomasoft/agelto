<?php

declare(strict_types=1);

use App\Services\PartitionCoverage;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (array_keys(config('partitioning.tables', [])) as $table) {
            app(PartitionCoverage::class)->ensure(
                table: $table,
                from: now('UTC')->startOfMonth()->subMonthsNoOverflow(12),
                to: now('UTC')->startOfMonth()->addMonthsNoOverflow(config('partitioning.premake') + 1),
            );
        }
    }

    public function down(): void
    {
        // Forward-only repair: never drop partitions that may already contain user data.
    }
};
