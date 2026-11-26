<?php

declare(strict_types=1);

use App\Models\Procurement\Process\DaemonHeartbeat;
use App\Models\Procurement\Process\Lane;
use App\Models\Procurement\Process\OutboxMessage;
use App\Models\Procurement\Process\WorkItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->parameters() as $table => $parameters) {
            $settings = [];
            foreach ($parameters as $name => $value) {
                $settings[] = "{$name} = {$value}";
            }

            DB::statement("ALTER TABLE {$table} SET (".implode(', ', $settings).')');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->parameters() as $table => $parameters) {
            DB::statement("ALTER TABLE {$table} RESET (".implode(', ', array_keys($parameters)).')');
        }
    }

    /** @return array<string, array<string, string>> */
    private function parameters(): array
    {
        return [
            (new WorkItem)->getTable() => [
                'fillfactor' => '75',
                'autovacuum_vacuum_scale_factor' => '0.01',
                'autovacuum_vacuum_threshold' => '500',
                'autovacuum_analyze_scale_factor' => '0.02',
                'autovacuum_vacuum_cost_limit' => '2000',
                'autovacuum_vacuum_cost_delay' => '2',
            ],
            (new OutboxMessage)->getTable() => [
                'fillfactor' => '75',
                'autovacuum_vacuum_scale_factor' => '0.005',
                'autovacuum_vacuum_threshold' => '200',
                'autovacuum_vacuum_cost_limit' => '2000',
                'autovacuum_vacuum_cost_delay' => '2',
            ],
            (new DaemonHeartbeat)->getTable() => [
                'fillfactor' => '50',
                'autovacuum_vacuum_scale_factor' => '0.0',
                'autovacuum_vacuum_threshold' => '50',
            ],
            (new Lane)->getTable() => [
                'fillfactor' => '60',
                'autovacuum_vacuum_scale_factor' => '0.0',
                'autovacuum_vacuum_threshold' => '100',
            ],
        ];
    }
};
