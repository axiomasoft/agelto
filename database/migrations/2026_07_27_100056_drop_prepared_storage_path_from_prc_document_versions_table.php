<?php

declare(strict_types=1);

use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Удаление `prepared_storage_path` (D25, P3.1): единственное представление версии переехало в
 * `prc_document_representations`, а два носителя одного факта запрещены. Данные не переносятся —
 * боевой парсинг не запускался, колонка пуста во всех окружениях; `down()` возвращает колонку
 * в исходном виде (nullable text с тем же комментарием).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn('prepared_storage_path');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->text('prepared_storage_path')->nullable()->comment('Excel→PDF и т.п. подготовленное представление');
        });
    }

    private function table(): string
    {
        return (new DocumentVersion)->getTable();
    }
};
