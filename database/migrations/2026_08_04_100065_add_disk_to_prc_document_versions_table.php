<?php

declare(strict_types=1);

use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Адресация файла версии (D11/D50/D114, P6.2: `research/07-storage-addressing.md` §2/§3) —
// колонка нужна: строка и файл вечные, retention их не касается. `null` — только историческая
// семантика («диск по конфигу на момент чтения»), материализуется backfill'ом (P6.5) ПЕРЕД
// сменой конфига (D51). Без `default`: дефолт замаскировал бы «писатель не записал диск».
return new class extends Migration
{
    public function up(): void
    {
        Schema::table((new DocumentVersion)->getTable(), function (Blueprint $table): void {
            $table->text('disk')
                ->nullable()
                ->after('storage_path')
                ->comment('null = диск документов config(crawler.documents_disk)');
        });
    }

    public function down(): void
    {
        Schema::table((new DocumentVersion)->getTable(), function (Blueprint $table): void {
            $table->dropColumn('disk');
        });
    }
};
