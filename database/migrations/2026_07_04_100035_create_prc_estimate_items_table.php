<?php

declare(strict_types=1);

use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Item;
use App\Models\Procurement\Estimate\Section;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('estimate_id')
                ->index()
                ->constrained(table: (new Estimate)->getTable())
                ->cascadeOnDelete();

            $table->foreignId('section_id')
                ->nullable()
                ->index()
                ->constrained(table: (new Section)->getTable())
                ->nullOnDelete();

            $table->integer('row_index');
            $table->text('code')->nullable()->comment('шифр ФЕР/ТЕР');
            $table->text('name');
            $table->text('unit')->nullable()->comment('цитата документа; база+множитель — соседние колонки');
            $table->text('unit_base')->nullable();
            $table->decimal('unit_multiplier', total: 18, places: 6)->nullable();
            $table->decimal('quantity', total: 18, places: 6)->nullable();

            // SR-01 (D93): унаследованные unit_price/total_price переименованы в *_curr (текущий
            // уровень), *_base — новые (базисный уровень).
            $table->decimal('unit_price_base', total: 18, places: 2)->nullable();
            $table->decimal('unit_price_curr', total: 18, places: 2)->nullable();
            $table->decimal('total_price_base', total: 18, places: 2)->nullable();
            $table->decimal('total_price_curr', total: 18, places: 2)->nullable();

            // SR-03 (D94): индекс пересчёта — скаляр на позиции, каталог <Indexes> не хранится.
            $table->text('index_code')->nullable();
            $table->decimal('index_value', total: 18, places: 6)->nullable();

            // SR-13/дополнение P4.3: номер позиции по ЛЮБОЙ сметной форме (не только акту), дробный.
            $table->text('source_position_number')->nullable();

            // SR-08/SR-09 (D97/D90): точка ссылки на вид работ — позиция. Без constrained() —
            // prc_estimate_work_types создаётся отдельной (более поздней по номеру) миграцией;
            // FK добавляется там через Schema::table(...)->foreign(...).
            $table->unsignedBigInteger('work_type_id')->nullable()->index();

            $table->jsonb('attributes')->nullable()->comment('modifiers[] + плоские ключи КС-3 (§4)');

            $table->timestamps();

            $table->unique(['estimate_id', 'row_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Item)->getTable();
    }
};
