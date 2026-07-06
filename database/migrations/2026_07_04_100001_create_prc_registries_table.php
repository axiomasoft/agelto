<?php

declare(strict_types=1);

use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('platform_id')
                ->constrained(table: (new Platform)->getTable())
                ->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->jsonb('settings')->nullable();
            $table->timestamps();

            $table->unique(['platform_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Registry)->getTable();
    }
};
