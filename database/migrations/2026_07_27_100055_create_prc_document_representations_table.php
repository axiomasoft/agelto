<?php

declare(strict_types=1);

use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Носитель представлений версии документа (D25, P3.1): одна версия — много представлений,
 * каждое со своим `kind`, путём и ИЗМЕРИМЫМИ признаками годности (`size_bytes`/`page_count`/
 * `token_estimate`). Пороги потребителя живут в capability-матрице модели, не в схеме (D26),
 * поэтому колонок-порогов здесь нет.
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
                ->cascadeOnDelete();

            // Текстом, не PG-enum: новый вид представления не должен требовать миграции (D25).
            $table->text('kind')->comment('значение App\Enums\Procurement\Document\RepresentationKind');

            // Canonical tuple is strict when present; legacy prepare remains usable until P3.5.
            $table->uuid('blob_id')->nullable();
            $table->binary('preparation_recipe_hash')->nullable();
            $table->binary('content_hash')->nullable();
            $table->text('range_key')->nullable();
            $table->text('tool_version')->nullable();
            $table->jsonb('coverage_manifest')->nullable();
            $table->smallInteger('coverage_manifest_schema_version')->nullable();
            $table->text('storage_path');
            $table->text('disk')->nullable()->comment('null = диск документов config(crawler.documents_disk)');

            $table->bigInteger('size_bytes')->nullable();
            $table->integer('page_count')->nullable();
            $table->integer('token_estimate')->nullable();

            $table->text('mime');
            $table->jsonb('meta')->default('{}')->comment('инструмент, версия инструмента, длительность генерации, исходный kind');

            $table->timestampTz('generated_at');

            $table->timestamps();

            $table->unique(['document_version_id', 'preparation_recipe_hash', 'kind', 'range_key'], 'prc_document_representations_recipe_range_unique');
        });
        DB::statement('CREATE UNIQUE INDEX prc_document_representations_legacy_kind_unique ON prc_document_representations(document_version_id,kind) WHERE preparation_recipe_hash IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new DocumentRepresentation)->getTable();
    }
};
