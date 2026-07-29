<?php

declare(strict_types=1);

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\AiDocumentType;
use App\Models\Procurement\Ai\Prompt;
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

            $table->text('document_type')->comment('шаг какого типа документа композирует промт: значение AiDocumentType либо сентинел any (кросс-документный промт), целостность — check-constraint (D16/D17)');
            $table->text('key');
            $table->text('step');
            $table->text('content');
            $table->integer('version');
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_protected')->default(false);

            $table->timestamps();

            $table->unique(['document_type', 'key', 'version']);
        });

        DB::statement(
            'ALTER TABLE prc_ai_prompts '
            .'ADD CONSTRAINT prc_ai_prompts_document_type_check '
            ."CHECK (document_type IN ({$this->documentTypeOrSentinelValues()}))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Prompt)->getTable();
    }

    private function documentTypeOrSentinelValues(): string
    {
        return collect([...AiDocumentType::values(), DocumentTypeScope::Any->value])
            ->map(static fn (string $value): string => "'{$value}'")
            ->implode(', ');
    }
};
