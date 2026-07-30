<?php

namespace App\Providers;

use App\Models\User;
use App\Pulse\Cards\LlmSpendCard;
use App\Pulse\Cards\PipelineStagesCard;
use App\Services\Llm\Contracts\UsageSource;
use App\Services\Llm\TrackingUsageSource;
use App\Services\Procurement\Analyzers\Estimate\ChunkSource;
use App\Services\Procurement\Analyzers\Estimate\RowRangeChunkSource;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use App\Services\Procurement\Contracts\ListingRowMapper;
use App\Services\Procurement\Eis\EisCsvRowMapper;
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

        // Источник расхода LLM (P4.2, контракт `root/usage-source-contract.md`, D34): реализация
        // одна — «наш трекинг» поверх `llm_calls`; второй (биллинг провайдера) нет и класса-заглушки
        // под неё не заводится, место второй реализации описано в контракте ТЕКСТОМ.
        $this->app->bind(UsageSource::class, TrackingUsageSource::class);

        // Реестр анализаторов типов документа (P2.3, §5.2 контракта): собирается из
        // `config('ai.analyzers')` при ПЕРВОМ резолве и дальше неизменен — отсюда singleton,
        // а не bind (иначе инварианты сборки проверялись бы на каждом резолве).
        $this->app->singleton(DocumentAnalyzerRegistry::class);
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

        // Pulse — отдельная страница за auth (Implementation Rules P7.5): ТОТ ЖЕ предикат, что
        // `User::canAccessPanel()` (super-admin или есть роль), а не «любой аутентифицированный» —
        // Pulse отдаёт SQL-текст медленных запросов, стектрейсы исключений и LLM-расход/стоимость,
        // это не менее чувствительно, чем сама Filament-панель (P7 phase-review, MED: предыдущая
        // версия открывала эти данные ЛЮБОМУ пользователю с аккаунтом, не только персоналу).
        Gate::define('viewPulse', fn (?User $user = null): bool => $user !== null && ($user->isSuperAdmin() || $user->getRoleNames()->isNotEmpty()));

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
