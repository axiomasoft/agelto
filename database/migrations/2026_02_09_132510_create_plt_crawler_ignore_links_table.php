<?php

declare(strict_types=1);

use App\Models\Crawler\IgnoredLink;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained((new App\Models\Platform())->getTable())->cascadeOnDelete();
            $table->string('external_id', 70)->index();
            $table->string('reason_category', 40)->nullable()->index();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            // Одна закупка игнорируется на площадке не более одного раза
            $table->unique(['platform_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new IgnoredLink())->getTable();
    }
};
