<?php

declare(strict_types=1);

use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Procurement;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `regulatory_base` хранит цитату редакции нормативов формы 421/пр;
 * дополнения и письмо индексов остаются в passport. `raw_result_disk` и `raw_result_ref`
 * адресуют сырой ответ LLM явно, без default диска. `validation_notes` хранит
 * ValidationReport::$notes; `verified_by` индексирован. Аудируемые FK сохраняют
 * `NO ACTION`/`RESTRICT`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('analysis_id')
                ->index()
                ->constrained(table: (new Analysis)->getTable())
                ->onDelete('NO ACTION');

            $table->foreignId('document_version_id')
                ->index()
                ->constrained(table: (new DocumentVersion)->getTable())
                ->onDelete('NO ACTION');

            // Nullable: обучающий контур работает и с документами без связанной закупки.
            $table->foreignId('procurement_id')
                ->nullable()
                ->index()
                ->constrained(table: (new Procurement)->getTable())
                ->onDelete('RESTRICT');

            // Площадка денормализована с документа прогона: смета доступна и без связанной закупки.
            $table->foreignId('platform_id')
                ->index()
                ->constrained(table: (new Platform)->getTable())
                ->onDelete('NO ACTION');

            // Порядковый номер сметы внутри одного анализа (в файле может быть несколько смет).
            $table->smallInteger('ordinal')->default(1);

            // `title` — УДАЛЕНА: производное значение, синтезируется аксессором Estimate::title().
            $table->text('estimate_number')->nullable();
            $table->decimal('total_amount', total: 18, places: 2)->nullable();
            $table->char('currency', length: 3)->nullable();
            $table->integer('base_year')->nullable();
            $table->text('price_level')->nullable();
            $table->text('regulatory_base')
                ->nullable()
                ->comment('редакция сметных нормативов — цитата документа; заполняет контракт LLM (PassportResponse)');

            // text + PHP backed enum CalcMethod, не Postgres native enum.
            $table->text('calc_method')->nullable();

            // Сводные трудозатраты; строчный уровень — prc_estimate_resources.resource_type='labor'.
            $table->decimal('labor_hours_workers', total: 18, places: 6)->nullable();
            $table->decimal('labor_hours_machinists', total: 18, places: 6)->nullable();

            // Реквизиты акта; договор подряда — ключи passport.contract_number/contract_date.
            $table->text('document_number')->nullable();
            $table->date('document_date')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->jsonb('passport')->nullable();

            // Сырой ответ LLM целиком — по решению «нормализованные + сырой jsonb», для переобработки/датасета.
            $table->jsonb('raw_result')->nullable();
            $table->text('raw_result_ref')->nullable()->comment('object storage только для payload > ~1МБ');
            $table->text('raw_result_disk')
                ->nullable()
                ->comment('null = диск сырого результата config(acquisition.raw_disk)');

            $table->integer('rows_expected')->nullable();
            $table->integer('rows_extracted')->nullable();
            $table->text('validation_status');
            $table->jsonb('validation_notes')->nullable()
                ->comment('ValidationReport::$notes сметы целиком — research/12-uncertainty-model.md §5');

            // Проверка оператором — отдельный факт с автором и временем.
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('verified_by')
                ->nullable()
                ->index()
                ->constrained(table: (new User)->getTable())
                ->nullOnDelete();

            // Адрес сметы в многосметном документе (дополнение) — заполняет БЭКЕНД;
            // система координат колонкой не хранится, вычисляется аксессором Estimate::sourceKind().
            $table->text('source_sheet_name')->nullable();
            $table->smallInteger('source_row_start')->nullable();
            $table->smallInteger('source_row_end')->nullable();
            $table->smallInteger('source_page_start')->nullable();
            $table->smallInteger('source_page_end')->nullable();

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
