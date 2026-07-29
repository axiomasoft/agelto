<?php

declare(strict_types=1);

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\AnalysisStep;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\EvalRun;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Ai\PromptSuggestion;
use App\Models\User;
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
            $table->uuid('uuid')->unique();

            $table->text('kind');
            $table->text('status')->default('pending');

            $table->foreignId('analysis_id')
                ->nullable()
                ->constrained(table: (new Analysis)->getTable())
                ->nullOnDelete();

            $table->foreignId('fixture_id')
                ->nullable()
                ->constrained(table: (new Fixture)->getTable())
                ->nullOnDelete();

            $table->foreignId('eval_run_id')
                ->nullable()
                ->constrained(table: (new EvalRun)->getTable())
                ->nullOnDelete();

            // Без constrained(): llm_calls партиционирован по called_at (составной PK
            // (id, called_at)) — Postgres не примет FK на одиночный id без отдельного unique-
            // индекса, а строки-партиции ротируются retention'ом (§9.1 спеки), так что жёсткая
            // ссылка всё равно бы протухала. Поле — происхождение предложения (какой вызов его
            // породил), не целостность.
            $table->unsignedBigInteger('llm_call_id')->nullable();

            $table->text('prompt_key')->nullable()->comment('для correction_hint пусто');
            $table->text('prompt_step')->nullable()->comment('для correction_hint пусто');
            $table->integer('base_version')->nullable()->comment('версия, поверх которой предложен патч');
            $table->text('proposed_content')->nullable()->comment('полный текст нового тела промта — для prompt_patch');
            $table->text('hint')->nullable()->comment('переформулировка для correction_hint');
            $table->text('document_type')->comment('предмет предложения (D21): значение AiDocumentType либо сентинел any (кросс-документный промт), заполняется для ВСЕХ форм kind — целостность check-constraint (D16/D17)');
            $table->text('analysis_step')->nullable()->comment('стадия AnalysisStep, к которой относится предложение (D21); NULL — предложение не привязано к одной стадии (патч кросс-стадийного промта global)');
            $table->text('rationale')->comment('чем критик обосновал предложение');
            $table->jsonb('evidence')->default('[]')->comment('выжимка diff: адреса расхождений');

            $table->foreignId('decided_by')
                ->nullable()
                ->constrained(table: (new User)->getTable())
                ->nullOnDelete();

            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->integer('applied_version')->nullable();
            $table->timestampTz('reverted_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('prompt_key');
        });

        DB::statement(
            'ALTER TABLE prc_ai_prompt_suggestions '
            .'ADD CONSTRAINT prc_ai_prompt_suggestions_document_type_check '
            ."CHECK (document_type IN ({$this->documentTypeOrSentinelValues()}))",
        );
        DB::statement(
            'ALTER TABLE prc_ai_prompt_suggestions '
            .'ADD CONSTRAINT prc_ai_prompt_suggestions_analysis_step_check '
            ."CHECK (analysis_step IS NULL OR analysis_step IN ({$this->analysisStepValues()}))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new PromptSuggestion)->getTable();
    }

    private function documentTypeOrSentinelValues(): string
    {
        return collect([...AiDocumentType::values(), DocumentTypeScope::Any->value])
            ->map(static fn (string $value): string => "'{$value}'")
            ->implode(', ');
    }

    private function analysisStepValues(): string
    {
        return collect(AnalysisStep::cases())
            ->map(static fn (AnalysisStep $case): string => "'{$case->value}'")
            ->implode(', ');
    }
};
