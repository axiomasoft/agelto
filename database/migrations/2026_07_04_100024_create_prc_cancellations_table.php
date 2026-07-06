<?php

declare(strict_types=1);

use App\Models\Procurement\Cancellation;
use App\Models\Procurement\DocumentVersion;
use App\Models\Procurement\Procurement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('procurement_id')
                ->index()
                ->constrained(table: (new Procurement)->getTable());

            $table->timestampTz('decision_at');
            $table->text('reason')->nullable();

            $table->foreignId('document_version_id')
                ->nullable()
                ->constrained(table: (new DocumentVersion)->getTable())
                ->nullOnDelete();

            // Отмена отмены — задним числом заполняемая колонка, без доп. логики на этом уровне.
            $table->timestampTz('reverted_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Cancellation)->getTable();
    }
};
