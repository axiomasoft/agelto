<?php

declare(strict_types=1);

use App\Models\Procurement\Estimate\Estimate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Носитель редакции сметных нормативов формы 421/пр (GAP-8, P5.3: `research/06-estimate-completeness.md`
// §2/§11) — цитата документа целиком, не парсится в номер/дату на бэкенде (тот же приём, что
// `passport.contract_date`). Реквизиты приказа о дополнениях и письма об индексах — ключи
// `passport.regulatory_amendments`/`passport.index_letter` (jsonb, правки колонки не требуют).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table((new Estimate)->getTable(), function (Blueprint $table): void {
            $table->text('regulatory_base')
                ->nullable()
                ->after('price_level')
                ->comment('редакция сметных нормативов — цитата документа; заполняет контракт LLM (PassportResponse)');
        });
    }

    public function down(): void
    {
        Schema::table((new Estimate)->getTable(), function (Blueprint $table): void {
            $table->dropColumn('regulatory_base');
        });
    }
};
