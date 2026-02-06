<?php

declare(strict_types=1);

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
        Schema::create('sys_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level', 20);
            $table->string('stage', 20);
            $table->string('source');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['stage', 'level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sys_logs');
    }
};
