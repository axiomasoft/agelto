<?php

declare(strict_types=1);

use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `prepared_storage_path` отсутствует по D25/P3.1: подготовленные версии живут только в
 * `prc_document_representations`, два носителя одного факта запрещены. Адресация файла версии
 * (P3.5) хранит обязательный `disk` и 32-byte SHA-256; адрес не зависит от конфига
 * в момент чтения. P7.5 F-01/F-02: `parent_version_id` индексирован, `document_id` явно NO ACTION.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('document_id')
                ->index()
                ->constrained(table: (new Document)->getTable())
                ->onDelete('NO ACTION');

            // P3.5: every source revision has complete canonical metadata.
            $table->bigInteger('revision_no');
            $table->uuid('blob_id');
            $table->text('etag')->nullable();
            $table->timestampTz('last_modified', precision: 6)->nullable();
            $table->timestampTz('first_seen_at', precision: 6);
            $table->text('normalized_entry_path')->nullable();
            $table->bigInteger('child_ordinal')->nullable();

            $table->binary('content_hash')->comment('sha256 содержимого файла');
            $table->unsignedBigInteger('size_bytes');
            $table->text('filename');
            $table->text('extension');
            $table->text('mime');

            // Только ссылка на object storage, immutable — сам файл здесь не хранится.
            $table->text('storage_path');
            $table->text('disk');

            $table->timestampTz('downloaded_at');

            // Дочерние версии из архивов (ZIP/RAR) ссылаются на родителя.
            $table->foreignId('parent_version_id')
                ->nullable()
                ->index()
                ->constrained(table: $this->table())
                ->noActionOnDelete();

            $table->timestamps();

            $table->unique(['document_id', 'revision_no']);
            $table->unique(['id', 'document_id']);
            $table->unique(['parent_version_id', 'normalized_entry_path']);
            $table->index('content_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new DocumentVersion)->getTable();
    }
};
