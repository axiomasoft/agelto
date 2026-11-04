<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Registry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Профиль хранит typed listing filter, cron, acquisition kind и версию конфигурации.
 * `route_profile_id` выбирает точный набор сетевых маршрутов; execution state и lease
 * принадлежат Process. Связь с реестром сохраняет `NO ACTION`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('registry_id')
                ->index()
                ->constrained(table: (new Registry)->getTable())
                ->onDelete('NO ACTION');

            $table->text('name');
            $table->unsignedBigInteger('profile_version')->default(1);
            $table->text('listing_kind')->default('fresh');

            $table->jsonb('filter')->nullable()->comment('даты/цены/ОКПД2/этапы');
            $table->text('schedule')->nullable()->comment('cron-выражение');

            $table->uuid('route_profile_id')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
        DB::statement("ALTER TABLE prc_crawlers ADD CONSTRAINT prc_crawlers_listing_kind_check CHECK (listing_kind IN ('fresh','archive','delta'))");
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
