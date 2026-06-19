<?php

declare(strict_types=1);

use App\Models\Order\PurchaseObject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained((new App\Models\Order\Order())->getTable())->onDelete('cascade');

            // Код ОКПД2 (например, 71.20.19.190)
            $table->string('okpd2', 20)->nullable()->index();

            // Наименование (Оказание услуг по контролю...)
            $table->text('name');

            // Характеристики (Кол-во, Цена)
            $table->decimal('quantity', 15, 3)->default(0); // 3 знака после запятой для точности
            $table->string('unit', 100)->nullable(); // усл ед, шт, кг
            $table->decimal('price', 15, 2)->nullable();
            $table->decimal('total_cost', 15, 2)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new PurchaseObject())->getTable();
    }
};
