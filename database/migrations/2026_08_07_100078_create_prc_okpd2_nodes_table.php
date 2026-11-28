<?php

declare(strict_types=1);

use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Platform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_id')
                ->constrained(table: (new Platform)->getTable())
                ->cascadeOnDelete();
            $table->string('code');
            $table->string('external_id');
            $table->string('parent_code')->nullable()->index();
            $table->unsignedTinyInteger('level');
            $table->string('name');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['platform_id', 'code']);
            $table->index(['platform_id', 'parent_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Okpd2Node)->getTable();
    }
};
