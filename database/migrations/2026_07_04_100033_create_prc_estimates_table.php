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
 * GAP-8/P5.3: `regulatory_base` хранит цитату редакции нормативов формы 421/пр; дополнения и
 * письмо индексов остаются в passport. D114/P6.2: `raw_result_disk` адресует сырой ответ LLM
 * вне ILM-префикса и образует пару с `raw_result_ref`, без default. P8.4/D136/D155/D74:
 * `validation_notes` хранит ValidationReport::$notes сметы. P7.5 F-01/F-02: `verified_by`
 * индексирован; аудируемые FK сохраняют явные `NO ACTION`/`RESTRICT`.
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

            // Nullable (D98): обучающий контур работает с документами вне закупки (D19) — guard,
            // пропускавший такие документы, снят в EstimatePersister.
            $table->foreignId('procurement_id')
                ->nullable()
                ->index()
                ->constrained(table: (new Procurement)->getTable())
                ->onDelete('RESTRICT');

            // Денормализация с документа прогона (§4.2 root/document-contour.md, D91, тот же
            // приём, что procurement_id выше, D98) — ответ на вопрос «с какой площадки эта смета»
            // без джойна через закупку и единственный носитель правила показа (§4).
            $table->foreignId('platform_id')
                ->index()
                ->constrained(table: (new Platform)->getTable())
                ->onDelete('NO ACTION');

            // Порядковый номер сметы внутри одного анализа (в файле может быть несколько смет).
            $table->smallInteger('ordinal')->default(1);

            // `title` — УДАЛЕНА (D100): производное значение, синтезируется аксессором Estimate::title().
            $table->text('estimate_number')->nullable();
            $table->decimal('total_amount', total: 18, places: 2)->nullable();
            $table->char('currency', length: 3)->nullable();
            $table->integer('base_year')->nullable();
            $table->text('price_level')->nullable();
            $table->text('regulatory_base')
                ->nullable()
                ->comment('редакция сметных нормативов — цитата документа; заполняет контракт LLM (PassportResponse)');

            // text + PHP backed enum CalcMethod (D105), не Postgres native enum.
            $table->text('calc_method')->nullable();

            // Сводные трудозатраты (SR-07); строчный уровень — prc_estimate_resources.resource_type='labor'.
            $table->decimal('labor_hours_workers', total: 18, places: 6)->nullable();
            $table->decimal('labor_hours_machinists', total: 18, places: 6)->nullable();

            // Реквизиты акта (SR-12, D102); договор подряда — ключи passport.contract_number/contract_date.
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

            // Ось «оператор проверил боевой разбор» (D89/D99) — без писателя внутри плана.
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('verified_by')
                ->nullable()
                ->index()
                ->constrained(table: (new User)->getTable())
                ->nullOnDelete();

            // Адрес сметы в многосметном документе (D106, дополнение P4.3) — заполняет БЭКЕНД;
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
