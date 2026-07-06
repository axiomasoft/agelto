<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Crawler\ProxyCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('proxy_id')
                ->index()
                ->constrained(table: (new Proxy)->getTable());

            $table->timestampTz('checked_at');
            $table->boolean('success');
            $table->integer('latency_ms')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new ProxyCheck)->getTable();
    }
};
