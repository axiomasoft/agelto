<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\Procurement\Platform\Platform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Legacy incident reader schema retained until the incident UI cutover. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create((new SourceIncident)->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_id')->constrained(table: (new Platform)->getTable())->cascadeOnDelete();
            $table->foreignId('crawler_id')->nullable()->constrained(table: (new Crawler)->getTable())->nullOnDelete();
            $table->unsignedBigInteger('run_id')->nullable()->comment('prc_crawler_runs.id; без FK — журнал переживает чистку прогонов');
            $table->unsignedBigInteger('fetch_id')->nullable()->comment('prc_fetches.id (партиционирована, без FK)');
            $table->text('type');
            $table->text('severity');
            $table->text('failure_kind')->nullable();
            $table->integer('http_status')->nullable();
            $table->jsonb('cursor')->nullable()->comment('Курсор прогона в момент инцидента');
            $table->jsonb('request_params')->nullable()->comment('Параметры запроса блока: где именно пропуск');
            $table->text('message');
            $table->jsonb('context')->nullable();
            $table->text('actor')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->text('acknowledged_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['platform_id', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index('run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((new SourceIncident)->getTable());
    }
};
