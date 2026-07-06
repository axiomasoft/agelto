<?php

declare(strict_types=1);

use App\Models\Crawler\Crawler;
use App\Models\Crawler\Run;
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

            $table->foreignId('crawler_id')
                ->index()
                ->constrained(table: (new Crawler)->getTable());

            $table->text('status');

            $table->jsonb('cursor')->nullable()->comment('день/ценовой стек/блок from/признак «дошли до известного»');
            $table->jsonb('stats')->nullable();

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('last_heartbeat_at')->nullable();

            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('last_error_at')->nullable();
            $table->timestampTz('next_retry_at')->nullable();
            $table->boolean('failed_permanently')->default(false);

            $table->timestamps();
        });

        // Partial unique index: только один running-прогон на crawler одновременно.
        DB::statement(
            'CREATE UNIQUE INDEX crw_runs_crawler_id_running_unique '
            ."ON prc_crawler_runs (crawler_id) WHERE status = 'running'",
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS crw_runs_crawler_id_running_unique');
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Run)->getTable();
    }
};
