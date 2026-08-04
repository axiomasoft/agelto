<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\Document;
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

            $table->text('code')->unique();
            $table->text('source_format');
            $table->text('storage_path');
            $table->text('sha256')->unique();
            $table->bigInteger('size_bytes');
            $table->text('mime');
            $table->text('original_filename');
            $table->text('source_path')->comment('относительный путь в корпусе-источнике — провенанс');
            $table->text('generator')->nullable();
            $table->text('cluster_id')->nullable()->index();
            $table->text('document_type')->comment('фактический тип файла = ожидаемый вердикт скрининга; значения — AiDocumentType, целостность — check-constraint (D16/D17, §6.1 document-type-axis.md)');
            $table->text('owner_document_type')->comment('тип, чей анализатор владеет корпусом и валидирует type_profile (§6.1 document-type-axis.md)');
            $table->text('title')->comment('человекочитаемое название эталона — для поиска и понимания, чисто читательское (D90, §6.3)');
            $table->jsonb('quirks')->default('[]');
            $table->jsonb('type_profile')->default('{}')->comment('поля профиля анализатора типа — class/form/expected_estimates/expected_rows/gold_source (§6.2 document-type-axis.md), без индексов (§6.4)');
            $table->boolean('is_verified')->default(false);

            // Прямая связь «эталон ↔ рабочая копия» (§2 root/document-contour.md): один эталон →
            // не более одной рабочей копии-документа. Удаление документа — эталон переживает
            // копию (ON DELETE SET NULL); удаление эталона документ НЕ трогает (FK смотрит сюда).
            $table->foreignId('document_id')
                ->nullable()
                ->unique()
                ->constrained(table: (new Document)->getTable())
                ->nullOnDelete();

            $table->foreignId('verified_by')
                ->nullable()
                ->constrained(table: (new User)->getTable())
                ->nullOnDelete();

            $table->timestampTz('verified_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE prc_ai_fixtures '
            .'ADD CONSTRAINT prc_ai_fixtures_document_type_check '
            ."CHECK (document_type IN ({$this->documentTypeValues()}))",
        );
        DB::statement(
            'ALTER TABLE prc_ai_fixtures '
            .'ADD CONSTRAINT prc_ai_fixtures_owner_document_type_check '
            ."CHECK (owner_document_type IN ({$this->documentTypeValues()}))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Fixture)->getTable();
    }

    private function documentTypeValues(): string
    {
        return collect(AiDocumentType::values())
            ->map(static fn (string $value): string => "'{$value}'")
            ->implode(', ');
    }
};
