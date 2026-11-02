<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Autovacuum thresholds for the remaining hot partitioned tables. */
return new class extends Migration
{
    private const array HOT_TABLES = ['prc_fetches'];

    private const array RELOPTIONS = [
        'autovacuum_vacuum_scale_factor' => '0.01',
        'autovacuum_analyze_scale_factor' => '0.01',
        'autovacuum_vacuum_insert_scale_factor' => '0.01',
    ];

    public function up(): void
    {
        foreach (self::HOT_TABLES as $table) {
            $this->alterReloptions($this->templateTableFor($table), $this->setClause());

            foreach ($this->existingPartitionsOf($table) as $partition) {
                $this->alterReloptions($partition, $this->setClause());
            }
        }
    }

    public function down(): void
    {
        $resetClause = implode(', ', array_keys(self::RELOPTIONS));

        foreach (self::HOT_TABLES as $table) {
            $this->alterReloptions($this->templateTableFor($table), $resetClause, reset: true);

            foreach ($this->existingPartitionsOf($table) as $partition) {
                $this->alterReloptions($partition, $resetClause, reset: true);
            }
        }
    }

    private function setClause(): string
    {
        return collect(self::RELOPTIONS)
            ->map(fn (string $value, string $option): string => "{$option} = {$value}")
            ->implode(', ');
    }

    private function templateTableFor(string $table): string
    {
        $row = DB::selectOne(
            'SELECT template_table FROM partman.part_config WHERE parent_table = ?',
            ['public.'.$table],
        );

        return $row->template_table;
    }

    /**
     * @return list<string>
     */
    private function existingPartitionsOf(string $table): array
    {
        $rows = DB::select(
            'SELECT c.relname AS name FROM pg_inherits i
                JOIN pg_class c ON c.oid = i.inhrelid
                JOIN pg_class p ON p.oid = i.inhparent
                WHERE p.relname = ?',
            [$table],
        );

        return array_map(static fn (object $row): string => (string) $row->name, $rows);
    }

    private function alterReloptions(string $table, string $clause, bool $reset = false): void
    {
        $verb = $reset ? 'RESET' : 'SET';
        DB::statement("ALTER TABLE {$table} {$verb} ({$clause})");
    }
};
