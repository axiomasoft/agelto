<?php

declare(strict_types=1);

use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\Fixture;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Реестр эталонных ФАЙЛОВ prc_ai_fixtures (D6) — отдельная сущность от версий эталонных ОТВЕТОВ
// prc_ai_dataset_examples. Правка чужой таблицы здесь стоит на D6 (решение владельца), схему СМЕТ
// (prc_estimate*) централизует P4 (D10) — этой миграции она не касается.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table((new DatasetExample)->getTable(), function (Blueprint $table): void {
            $table->foreignId('fixture_id')
                ->nullable()
                ->after('document_version_id')
                ->constrained(table: (new Fixture)->getTable())
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table((new DatasetExample)->getTable(), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fixture_id');
        });
    }
};
