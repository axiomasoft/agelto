<?php

declare(strict_types=1);

use App\Models\Procurement\Ai\Provider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->text('code')->unique()->comment('gemini/local-vllm/…');
            $table->text('driver');
            $table->text('base_url')->nullable();
            $table->jsonb('options')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });

        // Ключи API не хранятся в БД — только имя секрета (env/secrets).
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Provider)->getTable();
    }
};
