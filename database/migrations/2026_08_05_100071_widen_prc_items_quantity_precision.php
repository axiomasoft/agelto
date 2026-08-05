<?php

declare(strict_types=1);

use App\Models\Procurement\PurchaseItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P7.5, находка F-03 из research/08-db-review.md: `prc_items.quantity` несло
 * `numeric(15,3)` против `numeric(18,6)` у `prc_estimate_items`/`prc_estimate_resources` —
 * одно доменное понятие «количество» двумя точностями. Расширение точности не теряет
 * данных (D58 правило 4 — только РАСШИРЕНИЕ); 77 строк на боевом дереве.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE '.(new PurchaseItem)->getTable().' ALTER COLUMN quantity TYPE numeric(18,6)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE '.(new PurchaseItem)->getTable().' ALTER COLUMN quantity TYPE numeric(15,3)');
    }
};
