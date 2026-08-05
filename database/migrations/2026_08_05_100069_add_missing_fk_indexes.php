<?php

declare(strict_types=1);

use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Ai\Correction;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Ai\PromptSuggestion;
use App\Models\Procurement\Cancellation;
use App\Models\Procurement\Clarification;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Financing;
use App\Models\Procurement\Platform\Region;
use App\Models\Procurement\ProcurementParty;
use App\Models\Procurement\Protocol;
use App\Models\Procurement\PurchaseItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P7.5, находка F-01 из research/08-db-review.md: индексы под ведущие колонки 23 FK
 * (25 найдено ревизией минус 2 связи `model_has_roles`/`model_has_scopes` — таблицы
 * ПАКЕТА, их миграции не в этом проекте, см. F-01b гейта владельца). Все 15 таблиц ниже
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

        Schema::table((new Cancellation)->getTable(), function (Blueprint $table): void {
            $table->index('document_version_id');
        });

        Schema::table((new Clarification)->getTable(), function (Blueprint $table): void {
            $table->index('document_version_id');
        });

        Schema::table((new DocumentVersion)->getTable(), function (Blueprint $table): void {
            $table->index('parent_version_id');
        });

        Schema::table((new Estimate)->getTable(), function (Blueprint $table): void {
            $table->index('verified_by');
        });

        Schema::table((new Financing)->getTable(), function (Blueprint $table): void {
            $table->index('kbk_ref_id');
        });

        Schema::table((new PurchaseItem)->getTable(), function (Blueprint $table): void {
            $table->index('ktru_ref_id');
            $table->index('okpd2_ref_id');
        });

        Schema::table((new ProcurementParty)->getTable(), function (Blueprint $table): void {
            $table->index('organization_id');
        });

        Schema::table((new Protocol)->getTable(), function (Blueprint $table): void {
            $table->index('document_version_id');
        });

        Schema::table((new Region)->getTable(), function (Blueprint $table): void {
            $table->index('platform_id');
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

        Schema::table((new Cancellation)->getTable(), function (Blueprint $table): void {
            $table->dropIndex(['document_version_id']);
        });

        Schema::table((new Clarification)->getTable(), function (Blueprint $table): void {
            $table->dropIndex(['document_version_id']);
        });

        Schema::table((new DocumentVersion)->getTable(), function (Blueprint $table): void {
            $table->dropIndex(['parent_version_id']);
        });

        Schema::table((new Estimate)->getTable(), function (Blueprint $table): void {
            $table->dropIndex(['verified_by']);
        });

        Schema::table((new Financing)->getTable(), function (Blueprint $table): void {
            $table->dropIndex(['kbk_ref_id']);
        });

        Schema::table((new PurchaseItem)->getTable(), function (Blueprint $table): void {
            $table->dropIndex(['ktru_ref_id']);
            $table->dropIndex(['okpd2_ref_id']);
        });

        Schema::table((new ProcurementParty)->getTable(), function (Blueprint $table): void {
            $table->dropIndex(['organization_id']);
        });

        Schema::table((new Protocol)->getTable(), function (Blueprint $table): void {
            $table->dropIndex(['document_version_id']);
        });

        Schema::table((new Region)->getTable(), function (Blueprint $table): void {
            $table->dropIndex(['platform_id']);
        });
    }
};
