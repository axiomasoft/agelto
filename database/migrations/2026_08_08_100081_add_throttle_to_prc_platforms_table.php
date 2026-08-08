<?php

declare(strict_types=1);

use App\Models\Procurement\Platform\Platform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform settings are the shared layer because detail and document requests have no crawler
 * profile. Nullable fields inherit reviewed defaults from config/crawler.php (P4.1, D36).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->jsonb('throttle')->nullable()->comment('Budget and per-stage crawl settings; null inherits config defaults.');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn('throttle');
        });
    }

    private function table(): string
    {
        return (new Platform)->getTable();
    }
};
