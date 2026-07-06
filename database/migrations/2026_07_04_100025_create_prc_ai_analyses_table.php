<?php

declare(strict_types=1);

use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\DocumentVersion;
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

            $table->foreignId('document_version_id')
                ->index()
                ->constrained(table: (new DocumentVersion)->getTable());

            $table->text('step')->comment('screening/extraction');
            $table->text('status')->comment('pending→running→succeeded/failed/skipped');
            $table->text('document_type')->nullable()->comment('code из prc_document_types, без строгого FK');

            $table->jsonb('result')->nullable();
            $table->text('provider')->nullable();
            $table->text('model')->nullable();

            $table->text('locked_by')->nullable();
            $table->timestampTz('locked_until')->nullable();

            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('last_error_at')->nullable();
            $table->timestampTz('next_retry_at')->nullable();
            $table->boolean('failed_permanently')->default(false);

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            // Кэш и прогресс — перенос из v1 plt_document_analysis.
            $table->text('cache_id')->nullable()->comment('Gemini cachedContent — быстрая проверка валидности без похода в API');
            $table->timestampTz('cache_expires_at')->nullable();
            $table->text('rules_hash')->nullable()->comment('инвалидация кэша при смене версии промптов');
            $table->jsonb('progress')->nullable()->comment('резюме прерванной многошаговой экстракции');

            $table->timestamps();

            $table->unique(['document_version_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Analysis)->getTable();
    }
};
