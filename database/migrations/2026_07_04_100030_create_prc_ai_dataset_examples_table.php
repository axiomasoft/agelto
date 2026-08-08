<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Реестр версий эталонных ОТВЕТОВ связан с отдельным реестром эталонных ФАЙЛОВ (D6/D10).
 * `analysis_id` хранит точный анализ-предшественник DPO-снапшота (D56, P7.1), а не результат
 * `Analysis::active()`. P7.5 F-01/F-02: ведущие FK индексированы, документ сохраняет явный
 * `NO ACTION`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('document_version_id')
                ->index()
                ->constrained(table: (new DocumentVersion)->getTable())
                ->onDelete('NO ACTION');

            // Без constrained(): prc_ai_fixtures создаётся позже (100053); FK связывается там.
            $table->unsignedBigInteger('fixture_id')->nullable()->index();

            $table->foreignId('analysis_id')
                ->nullable()
                ->index()
                ->constrained(table: (new Analysis)->getTable())
                ->nullOnDelete();

            $table->text('document_type')->comment('тип разобранного документа — эталон всегда про конкретный документ, значения AiDocumentType, целостность — check-constraint (D16/D17, §3.5 document-type-axis.md)');
            $table->text('task')->comment('screening/extraction');
            $table->jsonb('gold')->nullable();
            $table->text('gold_ref')->nullable()->comment('object storage — для крупных эталонов');
            $table->text('source')->comment('llm/manual/corrected');

            $table->foreignId('verified_by')
                ->nullable()
                ->index()
                ->constrained(table: (new User)->getTable())
                ->nullOnDelete();

            $table->timestampTz('verified_at')->nullable();
            $table->integer('version')->default(1);

            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE prc_ai_dataset_examples '
            .'ADD CONSTRAINT prc_ai_dataset_examples_document_type_check '
            ."CHECK (document_type IN ({$this->documentTypeValues()}))",
        );
        DB::statement(
            'ALTER TABLE prc_ai_dataset_examples '
            .'ADD CONSTRAINT prc_ai_dataset_examples_task_check '
            ."CHECK (task IN ({$this->taskValues()}))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new DatasetExample)->getTable();
    }

    private function documentTypeValues(): string
    {
        return collect(AiDocumentType::values())
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
