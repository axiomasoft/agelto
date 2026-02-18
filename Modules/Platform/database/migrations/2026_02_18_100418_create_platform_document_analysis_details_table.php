<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Models\DocumentAnalysis\Details;
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
            $table->foreignId('document_analysis_id')->constrained((new DocumentAnalysis())->getTable())->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained($this->table())->cascadeOnDelete();
            $table->string('page_name', 200)->nullable();
            $table->unsignedSmallInteger('page_number')->default(1);
            $table->json('page_meta')->nullable();
            $table->json('content')->nullable();
            $table->longText('full_response')->nullable();

            $table->string('status', 20)->default('pending')->index();
            $table->dateTime('completed_at')->nullable();

            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
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
        return (new Details())->getTable();
    }
};
