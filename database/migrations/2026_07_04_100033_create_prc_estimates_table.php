<?php

declare(strict_types=1);

use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
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
            $table->uuid('uuid')->unique();

            $table->foreignId('analysis_id')
                ->index()
                ->constrained(table: (new Analysis)->getTable());

            $table->foreignId('document_version_id')
                ->index()
                ->constrained(table: (new DocumentVersion)->getTable());

            $table->foreignId('procurement_id')
                ->index()
                ->constrained(table: (new Procurement)->getTable());

            // Порядковый номер сметы внутри одного анализа (в файле может быть несколько смет).
            $table->smallInteger('ordinal')->default(1);

            $table->text('title');
            $table->text('estimate_number')->nullable();
            $table->decimal('total_amount', total: 18, places: 2)->nullable();
            $table->char('currency', length: 3)->nullable();
            $table->integer('base_year')->nullable();
            $table->text('price_level')->nullable();

            $table->jsonb('passport')->nullable();

            // Сырой ответ LLM целиком — по решению «нормализованные + сырой jsonb», для переобработки/датасета.
            $table->jsonb('raw_result')->nullable();
            $table->text('raw_result_ref')->nullable()->comment('object storage только для payload > ~1МБ');

            $table->integer('rows_expected')->nullable();
            $table->integer('rows_extracted')->nullable();
            $table->text('validation_status');

            $table->timestamps();

            $table->unique(['analysis_id', 'ordinal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Estimate)->getTable();
    }
};
