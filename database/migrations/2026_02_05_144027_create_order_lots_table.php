<?php

declare(strict_types=1);

use App\Models\Order\OrderLot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained((new \App\Models\Order\Order())->getTable())->onDelete('cascade');
            $table->integer('lot_number')->default(1);
            $table->text('subject')->nullable();
            $table->decimal('initial_price', 20, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new OrderLot())->getTable();
    }
};
