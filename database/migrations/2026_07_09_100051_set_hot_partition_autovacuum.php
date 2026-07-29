<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Понижает `autovacuum_*_scale_factor` (P7.7, Intent п.4, research/L §PG17) для hot-таблиц
 * с наибольшим объёмом вставок среди партиционированных — `llm_calls` (КАЖДЫЙ
 * LLM-вызов) и `prc_fetches` (краулинг). Дефолты Postgres (`autovacuum_vacuum_scale_factor`
 * 0.2, `autovacuum_analyze_scale_factor` 0.1) требуют десятки/сотни тысяч изменённых строк
 * на партицию до срабатывания — для append-only лога это означает устаревшую статистику
 * планировщика и позднюю обработку insert-triggered autovacuum (PG13+
 * `autovacuum_vacuum_insert_scale_factor`) на партиции, в которую пишут прямо сейчас.
 *
 * Два места применения (pg_partman-канон, template table): (1) `partman.template_public_*`
 * — сгенерирован автоматически `create_parent()` (100047) под каждую зарегистрированную
 * таблицу; ЛЮБОЙ `ALTER TABLE` на template-таблице подхватывается pg_partman для ВСЕХ
 * НОВЫХ партиций (declarative-партиционирование само по себе НЕ наследует reloptions
 * родителя — это ограничение Postgres, задокументированный повод существования template
 * table в pg_partman); (2) уже существующие партиции — template НЕ применяется ретроактивно,
 * их нужно альтерить явно.
 *
 * Идемпотентно по своей природе: `ALTER TABLE ... SET (reloption = value)` с тем же
 * значением — no-op, повторный прогон не бросает и не дублирует (в отличие от
 * `part_config.retention`, доп. проверка «уже выставлен» не нужна).
 */
return new class extends Migration
{
    private const array HOT_TABLES = ['llm_calls', 'prc_fetches'];

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
