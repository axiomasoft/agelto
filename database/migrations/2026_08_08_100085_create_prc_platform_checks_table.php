<?php

declare(strict_types=1);

use App\Models\Procurement\Health\PlatformCheck;
use App\Models\Procurement\Platform\Platform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * История проверок площадки не партиционируется: записей лишь единицы в сутки. Хеш эталона
 * хранится со строкой факта, чтобы результат был воспроизводим; пишет таблицу только репозиторий.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_id')->constrained(table: (new Platform)->getTable());
            $table->text('check_kind');
            $table->text('verdict');
            $table->jsonb('measured');
            $table->text('baseline_sha256')->nullable();
            $table->timestampTz('window_from')->nullable();
            $table->timestampTz('window_to')->nullable();
            $table->integer('sample_size');
            $table->integer('requests_used')->default(0);
            $table->text('message');
            $table->timestampTz('checked_at');
            $table->timestamps();
            $table->index(['platform_id', 'check_kind', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new PlatformCheck)->getTable();
    }
};
