<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Sys\Models\Proxy\Proxy;
use Sys\Models\Proxy\Service;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create($this->serviceTable(), function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('code', 16)->unique();
            $table->timestamps();
        });

        Schema::create($this->proxyTable(), function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->references('id')->on($this->serviceTable())->cascadeOnDelete();
            $table->string('username', 50);
            $table->string('password', 50);
            $table->string('ip_address', 32);
            $table->string('port', 6);
            $table->json('targets')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists($this->proxyTable());
        Schema::dropIfExists($this->serviceTable());
    }

    private function serviceTable(): string
    {
        return (new Service())->getTable();
    }

    private function proxyTable(): string
    {
        return (new Proxy())->getTable();
    }
};
