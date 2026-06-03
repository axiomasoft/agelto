<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Crawling\Link;

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
            $table->string('detail_url', 500);

            $table->string('reg_number', 60);
            $table->string('subject', 400)->nullable();
            $table->string('okved', 14)->nullable()->index();
            $table->string('okved_additional', 14)->nullable()->index();

            $table->dateTime('published_at')->nullable();
            $table->dateTime('platform_updated_at')->nullable();
            $table->dateTime('submission_end_at')->nullable();

            $table->string('status', 20)->default('pending')->index();
            $table->dateTime('completed_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_retry_at')->nullable();

            $table->timestamps();

            $table->unique(['platform_id', 'reg_number']);
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
        return (new Link())->getTable();
    }
};
