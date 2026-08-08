<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P7.5 F-02: `crawler_id` сохраняет прежний `NO ACTION` явно. После создания родителя здесь же
 * объявляется FK `prc_revisions.crawler_run_id` из F-04/100072: create-файлы не
 * перенумеровываются (D81), а `prc_revisions` создаётся раньше этого родителя.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('crawler_id')
                ->index()
                ->constrained(table: (new Crawler)->getTable())
                ->onDelete('NO ACTION');

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

            $table->timestampTz('stop_requested_at')->nullable();
            $table->text('stop_kind')->nullable();
            $table->foreignId('stop_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('queue_job_id')->nullable();

            $table->timestamps();
        });

        // Partial unique index: только один running-прогон на crawler одновременно.
        DB::statement(
            'CREATE UNIQUE INDEX prc_crawler_runs_crawler_id_running_unique '
            ."ON prc_crawler_runs (crawler_id) WHERE status = 'running'",
        );
        DB::statement(
            'ALTER TABLE prc_revisions ADD CONSTRAINT prc_revisions_crawler_run_id_foreign '
            .'FOREIGN KEY (crawler_run_id) REFERENCES prc_crawler_runs (id) ON DELETE NO ACTION',
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE prc_revisions DROP CONSTRAINT IF EXISTS prc_revisions_crawler_run_id_foreign',
        );
        DB::statement('DROP INDEX IF EXISTS prc_crawler_runs_crawler_id_running_unique');
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Run)->getTable();
    }
};
