<?php

declare(strict_types=1);

use App\Enums\Procurement\AiDocumentType;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Correction;
use App\Models\Procurement\Estimate\Estimate;
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

            $table->foreignId('analysis_id')
                ->index()
                ->constrained(table: (new Analysis)->getTable())
                ->cascadeOnDelete();

            $table->foreignId('estimate_id')
                ->nullable()
                ->constrained(table: (new Estimate)->getTable())
                ->nullOnDelete();

            $table->text('document_type')->comment('тип документа, к которому относится правка; значения — AiDocumentType, целостность — check-constraint (D16/D17)');
            $table->text('field_path')->nullable()->comment('JSONPath конкретного поля; null = весь документ');
            $table->text('reason')->comment('wrong_value/missed_row/extra_row/wrong_type/split_error/other');
            $table->text('content')->comment('текст оператора «что не так»');
            $table->text('hint')->nullable()->comment('переформулировка для промта; hint ?? content при подмешивании');
            $table->boolean('is_reusable')->default(false)->comment('применять ко всем документам этого document_type');

            $table->foreignId('created_by')
                ->constrained(table: (new User)->getTable());

            $table->timestampTz('applied_at')->nullable();

            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE prc_ai_corrections '
            .'ADD CONSTRAINT prc_ai_corrections_document_type_check '
            ."CHECK (document_type IN ({$this->documentTypeValues()}))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Correction)->getTable();
    }

    private function documentTypeValues(): string
    {
        return collect(AiDocumentType::values())
            ->map(static fn (string $value): string => "'{$value}'")
            ->implode(', ');
    }
};
