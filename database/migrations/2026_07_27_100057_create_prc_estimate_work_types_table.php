<?php

declare(strict_types=1);

use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Item;
use App\Models\Procurement\Estimate\WorkType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Виды работ и их иерархия принадлежат отдельной смете и хранят нормативы НР/СП.
 * `title` заполняют LLM-контракт и GSFX mapper; нормативы и `parent_id` заполняет
 * только GSFX mapper.
 */
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

            $table->foreignId('parent_id')
                ->nullable()
                ->index()
                ->constrained(table: $this->table())
                ->cascadeOnDelete();

            $table->integer('position');
            $table->text('title');

            $table->text('overhead_code')->nullable();
            $table->decimal('overhead_rate', total: 18, places: 6)->nullable();
            $table->decimal('overhead_rate_curr', total: 18, places: 6)->nullable();

            $table->text('profit_code')->nullable();
            $table->decimal('profit_rate', total: 18, places: 6)->nullable();
            $table->decimal('profit_rate_curr', total: 18, places: 6)->nullable();

            $table->timestamps();
        });

        // work_type_id объявлен в исходной миграции 100035 без constrained() — таблица видов
        // работ создаётся позже по номеру: FK добавляется здесь.
        Schema::table((new Item)->getTable(), function (Blueprint $table): void {
            $table->foreign('work_type_id')
                ->references('id')
                ->on($this->table())
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table((new Item)->getTable(), function (Blueprint $table): void {
            $table->dropForeign(['work_type_id']);
        });

        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new WorkType)->getTable();
    }
};
