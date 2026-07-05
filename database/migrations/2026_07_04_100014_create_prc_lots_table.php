<?php

declare(strict_types=1);

use App\Models\Procurement\Lot;
use App\Models\Procurement\Procurement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('procurement_id')
                ->constrained(table: (new Procurement)->getTable());

            $table->integer('lot_number');

            // 223-ФЗ — собственный GUID лота (lot-list.html); 44-ФЗ — null, весь объект закупки виртуальный лот №1.
            $table->text('lot_guid')->nullable();

            $table->text('subject');
            $table->text('status');
            $table->boolean('is_centralized')->default(false);
            $table->decimal('initial_price', total: 18, places: 2)->nullable();
            $table->char('currency', length: 3)->nullable();
            $table->text('okpd2_code')->nullable();
            $table->text('okved_code')->nullable();
            $table->jsonb('attributes')->nullable();

            $table->timestamps();

            $table->unique(['procurement_id', 'lot_number']);
        });

        // Partial unique index: lot_guid уникален в рамках закупки только когда не null (223-ФЗ).
        DB::statement(
            'CREATE UNIQUE INDEX prc_lots_procurement_lot_guid_unique '
            .'ON prc_lots (procurement_id, lot_guid) WHERE lot_guid IS NOT NULL',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS prc_lots_procurement_lot_guid_unique');
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Lot)->getTable();
    }
};
