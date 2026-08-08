<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Остаток AI/LLM-контура P7.5/F-02 после поглощения закупочных строк в P8.2: 12 FK сохраняют
 * явный `NO ACTION`, а `prc_estimates.procurement_id` получает `RESTRICT` по D133. Исходная
 * ревизия содержала 28 строк: 23 `NO ACTION` и пять аудитных детей `prc_procurements` с
 * `RESTRICT`; закупочная часть и строки снесённых таблиц перенесены/удалены в P8.2.
 *
 * `DROP CONSTRAINT` + `ADD CONSTRAINT … NOT VALID` + `VALIDATE CONSTRAINT` — данные не
 * трогаются, полная блокировка занимает время только `DROP`+`ADD NOT VALID` (быстро),
 * `VALIDATE` берёт облегчённую блокировку без остановки записи. **Отступление для
 * партиционированного `llm_calls`: Postgres 17 не поддерживает `NOT VALID` при добавлении FK
 * НА партиционированный
 * родитель (`SQLSTATE[42809]`, проверено на дереве) — для них констрейнт добавляется
 * обычным `ADD CONSTRAINT` без `NOT VALID`/`VALIDATE`; данные на этих таблицах уже
 * УДОВЛЕТВОРЯЮТ правилу (это redeclare того же `NO ACTION`/переход в `RESTRICT`, который
 * строже, а не слабее), поэтому проверка мгновенна на боевых объёмах (≤ 18 004 строк).
 */
return new class extends Migration
{
    private const array NO_ACTION_STAYS = [
        ['llm_calls', 'analysis_id', 'prc_ai_analyses'],
        ['llm_routes', 'provider_id', 'llm_providers'],
        ['prc_ai_analyses', 'analysis_run_id', 'prc_ai_analysis_runs'],
        ['prc_ai_analyses', 'document_version_id', 'prc_document_versions'],
        ['prc_ai_analysis_runs', 'document_version_id', 'prc_document_versions'],
        ['prc_ai_corrections', 'created_by', 'users'],
        ['prc_ai_dataset_examples', 'document_version_id', 'prc_document_versions'],
        ['prc_ai_eval_results', 'dataset_example_id', 'prc_ai_dataset_examples'],
        ['prc_ai_eval_results', 'eval_run_id', 'prc_ai_eval_runs'],
        ['prc_estimates', 'analysis_id', 'prc_ai_analyses'],
        ['prc_estimates', 'document_version_id', 'prc_document_versions'],
        ['prc_estimates', 'platform_id', 'prc_platforms'],
    ];

    private const array RESTRICT_AUDIT_CHILDREN = [
        ['prc_estimates', 'procurement_id', 'prc_procurements'],
    ];

    public function up(): void
    {
        foreach (self::NO_ACTION_STAYS as $row) {
            $this->redeclare(...$row, onDelete: 'NO ACTION');
        }

        foreach (self::RESTRICT_AUDIT_CHILDREN as $row) {
            $this->redeclare(...$row, onDelete: 'RESTRICT');
        }
    }

    public function down(): void
    {
        foreach ([...self::NO_ACTION_STAYS, ...self::RESTRICT_AUDIT_CHILDREN] as $row) {
            $this->redeclare(...$row, onDelete: 'NO ACTION');
        }
    }

    private function redeclare(string $table, string $column, string $references, string $onDelete): void
    {
        $constraint = "{$table}_{$column}_foreign";
        $partitioned = array_key_exists($table, config('partitioning.tables'));

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");

        if ($partitioned) {
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$constraint} "
                ."FOREIGN KEY ({$column}) REFERENCES {$references}(id) ON DELETE {$onDelete}",
            );

            return;
        }

        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$constraint} "
            ."FOREIGN KEY ({$column}) REFERENCES {$references}(id) ON DELETE {$onDelete} NOT VALID",
        );
        DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
    }
};
