<?php

declare(strict_types=1);

use App\Models\Procurement\Ai\Fixture;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Адресация исходного файла эталона (D11/D50/D114, P6.2: `research/07-storage-addressing.md`
// §2/§3) — колонка нужна: курируемый корпус живёт дольше любого поколения диска. Конфиг-ключ
// фолбэка ДРУГОЙ, чем у документов — `ai.fixtures.disk`, поэтому комментарий называет его явно.
// Без `default`: дефолт замаскировал бы «писатель не записал диск».
return new class extends Migration
{
    public function up(): void
    {
        Schema::table((new Fixture)->getTable(), function (Blueprint $table): void {
            $table->text('disk')
                ->nullable()
                ->after('storage_path')
                ->comment('null = диск эталонов config(ai.fixtures.disk)');
        });
    }

    public function down(): void
    {
        Schema::table((new Fixture)->getTable(), function (Blueprint $table): void {
            $table->dropColumn('disk');
        });
    }
};
