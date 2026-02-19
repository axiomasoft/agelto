<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Models\Crawling\LinkAttachment;
use Platform\Models\DocumentAnalysis\DocumentAnalysis;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_attachment_id')->nullable()->constrained((new LinkAttachment())->getTable())->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained($this->table())->cascadeOnDelete();

            $table->string('name', 500)->nullable();
            $table->string('extension', 12)->nullable();

            $table->string('status', 20)->default('pending')->index();
            $table->dateTime('completed_at')->nullable();

            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
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
        return (new DocumentAnalysis())->getTable();
    }
};
