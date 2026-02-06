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
        Schema::create('sys_proxies', function (Blueprint $table) {
            $table->id();
            $table->string('username', 50);
            $table->string('password', 50);
            $table->string('ip_address', 32);
            $table->string('port', 6);
            $table->boolean('is_protected')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sys_proxies');
    }
};
