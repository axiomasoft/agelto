<?php

namespace App\Providers;

use App\Services\Procurement\Contracts\ListingRowMapper;
use App\Services\Procurement\Eis\EisCsvRowMapper;
use App\Services\Procurement\Extraction\ChunkSource;
use App\Services\Procurement\Extraction\RowRangeChunkSource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Единственная реализация листинг-маппера в проекте (только ЕИС, `01-database.md`/`02-crawling.md`
        // не заводят второй источник в этом плане) — прямой bind без config-driven реестра (YAGNI).
        $this->app->bind(ListingRowMapper::class, EisCsvRowMapper::class);

        // Основной путь извлечения смет (D3/D40) — единственная реализация ChunkSource до P6.4
        // (`WindowedChunkSource` добавит оконный путь за отдельным гейтом, не сменой этого bind).
        $this->app->bind(ChunkSource::class, RowRangeChunkSource::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
