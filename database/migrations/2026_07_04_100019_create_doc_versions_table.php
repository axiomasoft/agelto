<?php

declare(strict_types=1);

use App\Models\Document\Document;
use App\Models\Document\DocumentVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('document_id')
                ->index()
                ->constrained(table: (new Document)->getTable());

            $table->text('content_hash')->comment('sha256 содержимого файла');
            $table->unsignedBigInteger('size_bytes');
            $table->text('filename');
            $table->text('extension');
            $table->text('mime');

            // Только ссылка на object storage, immutable — сам файл здесь не хранится.
            $table->text('storage_path');

            $table->timestampTz('downloaded_at');

            // Дочерние версии из архивов (ZIP/RAR) ссылаются на родителя.
            $table->foreignId('parent_version_id')
                ->nullable()
                ->constrained(table: $this->table())
                ->nullOnDelete();

            $table->text('prepared_storage_path')->nullable()->comment('Excel→PDF и т.п. подготовленное представление');

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
