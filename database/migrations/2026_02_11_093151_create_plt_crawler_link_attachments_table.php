<?php

declare(strict_types=1);

use App\Models\Crawler\Link;
use App\Models\Crawler\LinkAttachment;
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
            $table->foreignId('link_id')->constrained((new Link())->getTable())->cascadeOnDelete();
            $table->string('external_id', 60)->index();
            $table->string('name', 500);
            $table->string('extension', 12)->nullable();
            $table->string('url', 1000);
            $table->string('content_hash', 64)->nullable()->index();

            // Состояние скачивания файла + единый error-блок для ретраев
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->boolean('failed_permanently')->default(false);
            $table->timestamp('downloaded_at')->nullable();

            $table->timestamps();

            // Быстрая выборка готовых к ретраю и защита от дублей вложений
            $table->index(['status', 'next_retry_at']);
            $table->unique(['link_id', 'external_id']);
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
        return (new LinkAttachment())->getTable();
    }
};
