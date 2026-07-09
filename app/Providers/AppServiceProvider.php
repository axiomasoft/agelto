<?php

namespace App\Providers;

use App\Pulse\Cards\LlmSpendCard;
use App\Pulse\Cards\PipelineStagesCard;
use App\Services\Procurement\Contracts\ListingRowMapper;
use App\Services\Procurement\Eis\EisCsvRowMapper;
use App\Services\Procurement\Extraction\ChunkSource;
use App\Services\Procurement\Extraction\RowRangeChunkSource;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;

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
        // Кастомные Pulse-карточки (P7.5, D25) — классы вне `App\Livewire\*` (Files-список
        // item'а несёт `app/Pulse/**`), поэтому регистрация explicit, не Livewire-конвенцией.
        Livewire::component('pulse.pipeline-stages', PipelineStagesCard::class);
        Livewire::component('pulse.llm-spend', LlmSpendCard::class);

        // Pulse — отдельная страница за auth (Implementation Rules P7.5): любой аутентифицированный
        // пользователь панели (тот же guard, что Filament admin), не публичный дефолт "только local".
        Gate::define('viewPulse', fn ($user = null): bool => $user !== null);

        // Сквозной trace_id для КАЖДОЙ artisan-команды (P7.5, D25, Code Guidance: `cmd:<name>:
        // <uuid>`) — единый `CommandStarting`-листенер вместо правки каждой Procurement-команды
        // по отдельности (Required Reads item'а указывает на них лишь как на «точки старта»,
        // не как на цель редактирования; Files-список item'а их не несёт). `Context::add` здесь
        // же дегидрируется в payload любого job'а, диспатченного из команды.
        Event::listen(function (CommandStarting $event): void {
            Context::add('trace_id', 'cmd:'.$event->command.':'.(string) Str::uuid());
        });
    }
}
