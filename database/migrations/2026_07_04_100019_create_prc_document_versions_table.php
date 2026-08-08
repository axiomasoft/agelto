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
 * (D11/D50/D114, P6.2) хранит nullable `disk`; null означает диск документов из конфига на
 * момент чтения. P7.5 F-01/F-02: `parent_version_id` индексирован, `document_id` явно NO ACTION.
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

            $table->text('content_hash')->comment('sha256 содержимого файла');
            $table->unsignedBigInteger('size_bytes');
            $table->text('filename');
            $table->text('extension');
            $table->text('mime');

            // Только ссылка на object storage, immutable — сам файл здесь не хранится.
            $table->text('storage_path');
            $table->text('disk')->nullable()->comment('null = диск документов config(crawler.documents_disk)');

            $table->timestampTz('downloaded_at');

            // Дочерние версии из архивов (ZIP/RAR) ссылаются на родителя.
            $table->foreignId('parent_version_id')
                ->nullable()
                ->index()
                ->constrained(table: $this->table())
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(['document_id', 'content_hash']);
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
