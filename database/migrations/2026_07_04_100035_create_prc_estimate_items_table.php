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
            $table->text('unit')->nullable();
            $table->decimal('quantity', total: 18, places: 6)->nullable();
            $table->decimal('unit_price', total: 18, places: 2)->nullable();
            $table->decimal('total_price', total: 18, places: 2)->nullable();
            $table->jsonb('attributes')->nullable()->comment('коэффициенты/индексы');

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
