<?php

declare(strict_types=1);

use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\Protocol;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('procurement_id')
                ->index()
                ->constrained(table: (new Procurement)->getTable())
                ->cascadeOnDelete();

            // Закрытый codelist для 44-ФЗ, для 223-ФЗ — произвольная строка (см. ProtocolType).
            $table->text('type');

            $table->text('stage')->nullable();
            $table->text('source_number')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->timestampTz('published_at')->nullable();

            $table->foreignId('document_version_id')
                ->nullable()
                ->constrained(table: (new DocumentVersion)->getTable())
                ->nullOnDelete();

            $table->jsonb('payload')->nullable();

            $table->timestampTz('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Protocol)->getTable();
    }
};
