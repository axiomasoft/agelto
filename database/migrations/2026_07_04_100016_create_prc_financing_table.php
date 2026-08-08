<?php

declare(strict_types=1);

use App\Models\Procurement\Financing;
use App\Models\Procurement\Lot;
use App\Models\Procurement\Procurement;
use App\Models\Reference\Kbk;
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
                ->constrained(table: (new Procurement)->getTable())
                ->cascadeOnDelete();

            $table->foreignId('lot_id')
                ->nullable()
                ->index()
                ->constrained(table: (new Lot)->getTable())
                ->nullOnDelete();

            $table->text('kind');
            $table->text('kbk')->nullable();

            $table->foreignId('kbk_ref_id')
                ->nullable()
                ->index() // P7.5, F-01: индекс ведущей колонки FK.
                ->constrained(table: (new Kbk)->getTable())
                ->nullOnDelete();

            $table->text('kvr')->nullable();
            $table->text('receipt_code')->nullable();
            $table->integer('fiscal_year')->nullable();

            // Бакет «последующие годы» вне горизонта разбивки: fiscal_year IS NULL AND is_beyond_horizon.
            $table->boolean('is_beyond_horizon')->default(false);

            $table->decimal('amount', total: 18, places: 2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Financing)->getTable();
    }
};
