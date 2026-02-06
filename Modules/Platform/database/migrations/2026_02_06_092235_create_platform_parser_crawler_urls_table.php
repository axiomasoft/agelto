<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Models\Parser\ParserCrawlerUrl;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->foreignId('parser_id')->constrained((new \Platform\Models\Parser\Parser())->getTable())->cascadeOnDelete();
            $table->string('reg_number')->unique();
            $table->string('detail_url', 500);

            $table->dateTime('published_at')->nullable();
            $table->dateTime('eis_updated_at')->nullable();
            $table->dateTime('submission_end_at')->nullable();

            $table->string('status', 20)->default('pending');
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_retry_at')->nullable();

            $table->timestamps();
            $table->index(['status', 'next_retry_at']);
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
        return (new ParserCrawlerUrl())->getTable();
    }
};
