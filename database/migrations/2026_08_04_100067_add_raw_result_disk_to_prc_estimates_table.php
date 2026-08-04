<?php

declare(strict_types=1);

use App\Models\Procurement\Estimate\Estimate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Адресация сырого ответа LLM > ~1 МБ (D114, P6.2: `research/07-storage-addressing.md` §2/§3) —
// расхождение с буквальным D50: `prc_estimates` в `partitioning.eternal`, а префикс
// `ai/estimates/` под ILM-правило не попадает — вечная строка + вечный файл вне двух подтверждённых
// носителей. Имя `raw_result_disk`, не `disk` — пара с `raw_result_ref` обязана читаться как пара.
// Без `default`: дефолт замаскировал бы «писатель не записал диск».
return new class extends Migration
{
    public function up(): void
    {
        Schema::table((new Estimate)->getTable(), function (Blueprint $table): void {
            $table->text('raw_result_disk')
                ->nullable()
                ->after('raw_result_ref')
                ->comment('null = диск сырого результата config(crawler.raw_disk)');
        });
    }

    public function down(): void
    {
        Schema::table((new Estimate)->getTable(), function (Blueprint $table): void {
            $table->dropColumn('raw_result_disk');
        });
    }
};
