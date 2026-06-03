<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Crawling\Link;
use App\Models\Crawling\LinkAttachment;

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
            $table->string('status', 20)->default('pending');
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
        return (new LinkAttachment())->getTable();
    }
};
