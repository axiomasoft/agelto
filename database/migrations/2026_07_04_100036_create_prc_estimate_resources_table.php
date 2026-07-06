<?php

declare(strict_types=1);

use App\Models\Procurement\Estimate\Item;
use App\Models\Procurement\Estimate\Resource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('item_id')
                ->index()
                ->constrained(table: (new Item)->getTable());

            // text + PHP Backed enum (App\Enums\Procurement\ResourceType), не Postgres native enum.
            $table->text('resource_type')->comment('labor/machine/material/overhead/other');
            $table->text('code')->nullable();
            $table->text('name');
            $table->text('unit')->nullable();
            $table->decimal('quantity', total: 18, places: 6)->nullable();
            $table->decimal('price', total: 18, places: 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Resource)->getTable();
    }
};
