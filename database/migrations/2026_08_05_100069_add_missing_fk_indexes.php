<?php

declare(strict_types=1);

use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Ai\Correction;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Ai\PromptSuggestion;
use App\Models\Procurement\Estimate\Estimate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Остаток AI/LLM-контура P7.5/F-01 после поглощения закупочных строк в P8.2: 14 индексов.
 * Исходная ревизия нашла 23 FK-индекса (ещё 2 связи пакета не принадлежат проекту). Таблицы
 * < 1 МБ на боевом дереве (`findings/P7-schema-inventory.md` §2.1), поэтому обычный
 * `CREATE INDEX` допустим без `CONCURRENTLY` (Implementation Rules P7.5, п.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table((new Analysis)->getTable(), function (Blueprint $table): void {
            $table->index('parent_analysis_id');
            $table->index('reviewed_by');
        });

        Schema::table((new AnalysisRun)->getTable(), function (Blueprint $table): void {
            $table->index('initiated_by');
        });

        Schema::table((new Correction)->getTable(), function (Blueprint $table): void {
            $table->index('created_by');
            $table->index('estimate_id');
        });

        Schema::table((new DatasetExample)->getTable(), function (Blueprint $table): void {
            $table->index('analysis_id');
            $table->index('fixture_id');
            $table->index('verified_by');
        });

        Schema::table((new Fixture)->getTable(), function (Blueprint $table): void {
            $table->index('verified_by');
        });

        Schema::table((new PromptSuggestion)->getTable(), function (Blueprint $table): void {
            $table->index('analysis_id');
            $table->index('decided_by');
            $table->index('eval_run_id');
            $table->index('fixture_id');
        });

        Schema::table((new Estimate)->getTable(), function (Blueprint $table): void {
            $table->index('verified_by');
        });
    }

    public function down(): void
    {
        Schema::table((new Analysis)->getTable(), function (Blueprint $table): void {
            $table->dropIndex(['parent_analysis_id']);
            $table->dropIndex(['reviewed_by']);
        });

        Schema::table((new AnalysisRun)->getTable(), function (Blueprint $table): void {
            $table->dropIndex(['initiated_by']);
        });

        Schema::table((new Correction)->getTable(), function (Blueprint $table): void {
            $table->dropIndex(['created_by']);
            $table->dropIndex(['estimate_id']);
        });

        Schema::table((new DatasetExample)->getTable(), function (Blueprint $table): void {
            $table->dropIndex(['analysis_id']);
            $table->dropIndex(['fixture_id']);
            $table->dropIndex(['verified_by']);
        });

        Schema::table((new Fixture)->getTable(), function (Blueprint $table): void {
            $table->dropIndex(['verified_by']);
        });

        Schema::table((new PromptSuggestion)->getTable(), function (Blueprint $table): void {
            $table->dropIndex(['analysis_id']);
            $table->dropIndex(['decided_by']);
            $table->dropIndex(['eval_run_id']);
            $table->dropIndex(['fixture_id']);
        });

        Schema::table((new Estimate)->getTable(), function (Blueprint $table): void {
            $table->dropIndex(['verified_by']);
        });
    }
};
