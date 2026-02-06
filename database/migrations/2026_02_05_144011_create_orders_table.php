<?php

declare(strict_types=1);

use App\Models\Order\Order;
use App\Models\Order\OrderCustomer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->string('reg_number')->index(); // Номер извещения
            $table->integer('edition_number')->default(1); // Редакция
            $table->text('name'); // Наименование объекта закупки
            $table->string('status')->nullable();

            // Связи
            $table->foreignId('customer_id')->constrained((new OrderCustomer())->getTable());
            $table->foreignId('platform_id')->constrained((new \Platform\Models\Platform())->getTable());

            // Финансы
            $table->decimal('initial_price', 20, 2)->nullable();
            $table->string('currency', 3)->default('RUB');

            // Даты из ЕИС
            $table->dateTime('published_at')->nullable();     // Размещено
            $table->dateTime('eis_updated_at')->nullable();       // Изменено (контроль повторного парсинга)
            $table->dateTime('submission_start_at')->nullable(); // Начало подачи
            $table->dateTime('submission_end_at')->nullable();   // Конец подачи
            $table->dateTime('scoring_at')->nullable();          // Итоги/Рассмотрение

            $table->timestamps(); // Внутренние даты Laravel
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Order())->getTable();
    }
};
