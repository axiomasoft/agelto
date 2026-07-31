<?php

declare(strict_types=1);

use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\DatasetExample;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Носитель предшественника DPO-пары (D56, P7.1): резолв через Analysis::active() резолвит один и
// тот же анализ для КАЖДОЙ gold-версии одной версии документа — analysis_id хранит АНАЛИЗ, из
// результата которого снят ИМЕННО ЭТОТ снапшот. НОВЫЙ файл, а не правка create-миграции P1.4
// (D56): к P7 корпус штатно накапливается, `migrate:fresh` стирает его.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table((new DatasetExample)->getTable(), function (Blueprint $table): void {
            $table->foreignId('analysis_id')
                ->nullable()
                ->after('fixture_id')
                ->constrained(table: (new Analysis)->getTable())
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table((new DatasetExample)->getTable(), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('analysis_id');
        });
    }
};
