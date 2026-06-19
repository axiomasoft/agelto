<?php

declare(strict_types=1);

use App\Models\Geo\Country;
use App\Models\Geo\Location;
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
        Schema::create((new Location)->getTable(), function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained((new Country)->getTable());
            $table->foreignId('parent_id')->nullable()->constrained((new Location)->getTable());
            $table->string('name')->index();
            $table->string('add_name')->nullable();
            $table->string('genitive_name')->nullable();
            $table->string('parent_name')->nullable();
            $table->string('state')->nullable();
            $table->string('slug')->unique()->nullable();
            $table->string('type', 16)->nullable()->index()->comment('Тип локации: область, город, поселок ...');
            $table->unsignedBigInteger('curator_id')->nullable();
            $table->unsignedSmallInteger('region_number')->nullable();
            $table->unsignedBigInteger('phone_code')->nullable();
            $table->json('description')->nullable()->comment('Описание');
            $table->string('timezone', 100)->nullable();
            $table->geometry(column: 'geo_point', subtype: 'point')->nullable();
            $table->geometry(column: 'geo_borders', subtype: 'multilinestring')->nullable();
            $table->unsignedBigInteger('kladr_id')->index()->nullable();
            $table->string('fias_code', 38)->index()->nullable();
            $table->string('fias_path')->nullable()->index();
            $table->smallInteger('fias_object_level')->nullable();
            $table->unsignedMediumInteger('population')->default(0);
            $table->unsignedMediumInteger('distance_to_big')->nullable();
            $table->boolean('capital')->default(false);
            $table->boolean('is_show')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists((new Location)->getTable());
    }
};
