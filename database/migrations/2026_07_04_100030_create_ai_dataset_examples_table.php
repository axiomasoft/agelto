<?php

declare(strict_types=1);

use App\Models\Ai\DatasetExample;
use App\Models\Document\DocumentVersion;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('document_version_id')
                ->index()
                ->constrained(table: (new DocumentVersion)->getTable());

            $table->text('task')->comment('screening/extraction');
            $table->jsonb('gold')->nullable();
            $table->text('gold_ref')->nullable()->comment('object storage — для крупных эталонов');
            $table->text('source')->comment('llm/manual/corrected');

            $table->foreignId('verified_by')
                ->nullable()
                ->constrained(table: (new User)->getTable())
                ->nullOnDelete();

            $table->timestampTz('verified_at')->nullable();
            $table->integer('version')->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new DatasetExample)->getTable();
    }
};
