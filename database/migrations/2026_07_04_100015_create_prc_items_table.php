<?php

declare(strict_types=1);

use App\Models\Procurement\Lot;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\PurchaseItem;
use App\Models\Reference\Ktru;
use App\Models\Reference\Okpd2;
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

            $table->integer('position_number')->nullable();
            $table->text('name');
            $table->text('characteristics')->nullable();

            // OCDS tender.items: схема классификации заменяема — окпд2/ктру/будущие.
            $table->text('classifier_scheme');
            $table->text('classifier_code')->index();

            $table->foreignId('okpd2_ref_id')
                ->nullable()
                ->constrained(table: (new Okpd2)->getTable())
                ->nullOnDelete();

            $table->foreignId('ktru_ref_id')
                ->nullable()
                ->constrained(table: (new Ktru)->getTable())
                ->nullOnDelete();

            $table->decimal('quantity', total: 15, places: 3)->nullable();
            $table->text('unit')->nullable();
            $table->decimal('unit_price', total: 18, places: 2)->nullable();
            $table->decimal('total_cost', total: 18, places: 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new PurchaseItem)->getTable();
    }
};
