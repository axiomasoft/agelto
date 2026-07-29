<?php

declare(strict_types=1);

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\AnalysisStep;
use App\Models\Procurement\Ai\EvalRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->text('document_type')->comment('тип разбираемого документа в этом прогоне, либо сентинел any для кросс-документного прогона скрининга — значения AiDocumentType, целостность check-constraint (D16/D17, §3.4 document-type-axis.md)');
            $table->text('task')->comment('screening/extraction — какую задачу прогоняет этот EvalRun');
            $table->text('dataset_version');
            $table->text('provider');
            $table->text('model');
            $table->jsonb('prompt_version_set')->nullable();
            $table->jsonb('metrics')->nullable()->comment('precision/recall/F1, row_coverage');
            $table->text('ci_status')->comment('pass/fail');

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE prc_ai_eval_runs '
            .'ADD CONSTRAINT prc_ai_eval_runs_document_type_check '
            ."CHECK (document_type IN ({$this->documentTypeOrSentinelValues()}))",
        );
        DB::statement(
            'ALTER TABLE prc_ai_eval_runs '
            .'ADD CONSTRAINT prc_ai_eval_runs_task_check '
            ."CHECK (task IN ({$this->taskValues()}))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new EvalRun)->getTable();
    }

    private function documentTypeOrSentinelValues(): string
    {
        return collect([...AiDocumentType::values(), DocumentTypeScope::Any->value])
            ->map(static fn (string $value): string => "'{$value}'")
            ->implode(', ');
    }

    private function taskValues(): string
    {
        return collect(AnalysisStep::cases())
            ->map(static fn (AnalysisStep $case): string => "'{$case->value}'")
            ->implode(', ');
    }
};
