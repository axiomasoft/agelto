<?php

declare(strict_types=1);

use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Procurement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Документы запрещают удаление закупки (`RESTRICT`); связь с площадкой сохраняет `NO ACTION`.
 * Категория документа хранится в `kind`, execution ownership принадлежит Process.
 * Source identity и acquisition cycles позволяют повторно проверять документ без
 * дублирования его каталожной строки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('procurement_id')
                ->nullable()
                ->index()
                ->constrained(table: (new Procurement)->getTable())
                ->onDelete('RESTRICT');

            $table->foreignId('platform_id')
                ->index()
                ->constrained(table: (new Platform)->getTable())
                ->onDelete('NO ACTION');

            // uid вложения в filestore ЕИС.
            $table->text('external_id')->nullable();
            // Source identity and access metadata for document acquisition.
            $table->text('source_document_key')->nullable()->collation('C');
            $table->integer('source_key_version')->nullable();
            $table->jsonb('access_descriptor')->nullable();
            $table->integer('access_schema_version')->nullable();
            $table->bigInteger('access_descriptor_version')->nullable();
            $table->timestampTz('reference_observed_at', precision: 6)->nullable();

            $table->text('title');
            $table->text('kind')->nullable()->comment('извещение/протокол/контракт/… — свободный текст источника');
            $table->text('source_url')->nullable();
            $table->text('status');

            // Партиционированная таблица — FK не строим, только ссылка.
            $table->unsignedBigInteger('first_seen_revision_id')->nullable();

            $table->timestampTz('removed_at')->nullable();

            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('last_error_at')->nullable();
            $table->timestampTz('next_retry_at')->nullable();
            $table->boolean('failed_permanently')->default(false);
            $table->text('locked_by')->nullable();
            $table->timestampTz('locked_until')->nullable();

            $table->timestamps();

            // Subject head и выделение download/revalidation cycles сериализуются FOR UPDATE этой row.
            $table->bigInteger('head_generation')->default(1);
            $table->bigInteger('acquisition_cycle_seq')->default(0);
            $table->timestampTz('next_revalidation_due_at', precision: 6)->nullable();
        });

        DB::statement(
            'ALTER TABLE prc_documents '
            .'ADD CONSTRAINT prc_documents_head_generation_check CHECK (head_generation >= 1), '
            .'ADD CONSTRAINT prc_documents_acquisition_cycle_seq_check CHECK (acquisition_cycle_seq >= 0), '
            .'ADD CONSTRAINT prc_documents_reference_check CHECK ((source_document_key IS NULL AND source_key_version IS NULL AND access_descriptor IS NULL AND access_schema_version IS NULL AND access_descriptor_version IS NULL AND reference_observed_at IS NULL) OR '
            ."(source_document_key IS NOT NULL AND octet_length(source_document_key) BETWEEN 1 AND 1024 AND source_key_version IS NOT NULL AND source_key_version>=1 AND access_descriptor IS NOT NULL AND jsonb_typeof(access_descriptor)='object' AND access_schema_version IS NOT NULL AND access_schema_version=1 AND access_descriptor_version IS NOT NULL AND access_descriptor_version>=1 AND reference_observed_at IS NOT NULL))",
        );
        DB::statement('CREATE UNIQUE INDEX prc_documents_source_key_unique ON prc_documents(platform_id,source_document_key) WHERE source_document_key IS NOT NULL');
        Schema::create('prc_procurement_documents', function (Blueprint $table): void {
            $table->foreignId('procurement_id')->constrained('prc_procurements')->noActionOnDelete();
            $table->foreignId('document_id')->constrained('prc_documents')->noActionOnDelete();
            $table->timestampTz('observed_at', precision: 6);
            $table->timestampTz('removed_at', precision: 6)->nullable();
            $table->primary(['procurement_id', 'document_id']);
        });

        // Partial unique index: external_id уникален в рамках закупки только когда не null.
        DB::statement(
            'CREATE UNIQUE INDEX prc_documents_procurement_external_id_unique '
            .'ON prc_documents (procurement_id, external_id) WHERE external_id IS NOT NULL',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('prc_procurement_documents');
        DB::statement('DROP INDEX IF EXISTS prc_documents_procurement_external_id_unique');
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Document)->getTable();
    }
};
