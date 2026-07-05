<?php

declare(strict_types=1);

use App\Models\Crawler\Crawler;
use App\Models\Crawler\CrawlerRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->foreignId('crawler_id')->constrained((new Crawler())->getTable())->cascadeOnDelete();
            $table->string('status', 20)->default('running');

            // Иерархический курсор обхода — для возобновления с последней точки
            $table->string('cursor_url', 1000)->nullable();
            $table->unsignedInteger('current_page')->nullable();
            $table->decimal('price_from', 20, 2)->nullable();
            $table->decimal('price_to', 20, 2)->nullable();
            $table->json('price_stack')->nullable();
            $table->date('day_from')->nullable();
            $table->date('day_to')->nullable();
            $table->unsignedInteger('cursor_offset')->nullable();

            $table->json('stats')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable()->index();

            // Единый error-блок
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->boolean('failed_permanently')->default(false);

            $table->timestamps();

            $table->index(['crawler_id', 'status']);
        });

        // Инвариант: на один профиль краулера — не более одного активного (running) прогона.
        DB::statement(
            'CREATE UNIQUE INDEX plt_crawler_runs_one_active_per_crawler ON '
            .(new CrawlerRun())->getTable()." (crawler_id) WHERE status = 'running'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new CrawlerRun())->getTable();
    }
};
