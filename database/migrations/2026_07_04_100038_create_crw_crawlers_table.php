<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler;
use App\Models\Procurement\Registry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('registry_id')
                ->index()
                ->constrained(table: (new Registry)->getTable());

            $table->text('name');
            $table->text('mode');
            $table->text('source_method');
            $table->text('fallback_method')->nullable();

            $table->jsonb('filter')->nullable()->comment('даты/цены/ОКПД2/этапы');
            $table->text('schedule')->nullable()->comment('cron-выражение');
            $table->jsonb('options')->nullable()->comment('recordsPerPage, лимиты дробления, колонки CSV');

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Crawler)->getTable();
    }
};
