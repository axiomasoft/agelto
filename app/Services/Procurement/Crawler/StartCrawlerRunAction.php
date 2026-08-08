<?php

declare(strict_types=1);

namespace App\Services\Procurement\Crawler;

use App\Dto\Procurement\Crawler\Contracts\HasArchiveWindow;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Exceptions\Procurement\Crawler\ArchiveWindowMissing;
use App\Exceptions\Procurement\Crawler\CrawlerRunAlreadyActive;
use App\Jobs\Procurement\CrawlRunJob;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use Carbon\CarbonInterface;

/**
 * Единственный носитель правила «можно ли запускать» (P3.4, Implementation Rules п. 3):
 * консольные команды и кнопки админки зовут этот класс, а не повторяют проверку активного
 * прогона сами — иначе поведение консоли и админки разошлось бы ровно там, где бриф требует
 * одинаковости. Вторая PHP-проверка внутри `CrawlerRunManager::startOrResume()` не заводится
 * (докблок класса это запрещает, `research/04-p3-runs-design.md` §6 п. 4/5) — отказ формирует
 * ТОЛЬКО вызывающий, до обращения к процессору.
 *
 * `$mode` — override поверх `$crawler->mode`: нужен `crawler:run-fresh`, которая (как и до этого
 * item'а) форсирует fresh-скан по явному id/name профиля независимо от сохранённого режима
 * профиля; кнопки админки override не передают — используют `$crawler->mode`.
 */
final readonly class StartCrawlerRunAction
{
    public function start(
        Crawler $crawler,
        ?CrawlerMode $mode = null,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): void {
        $this->guardNoActiveRun($crawler);
        $this->dispatch($crawler, $mode, $from, $to);
    }

    /**
     * «Запустить с начала» (D25): курсор возобновляемого прогона обнуляется ЯВНО перед стартом,
     * сам прогон (история) не удаляется — обнулённый курсор подхватит
     * `CrawlerRunManager::startOrResume()`, возобновив тот же прогон с чистого места.
     */
    public function restart(
        Crawler $crawler,
        ?CrawlerMode $mode = null,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): void {
        $this->guardNoActiveRun($crawler);

        $this->resumableRun($crawler)?->update(['cursor' => (new ListingCursor)->toArray()]);

        $this->dispatch($crawler, $mode, $from, $to);
    }

    public function resume(
        Crawler $crawler,
        ?CrawlerMode $mode = null,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): void {
        $this->guardNoActiveRun($crawler);
        $this->dispatch($crawler, $mode, $from, $to);
    }

    private function guardNoActiveRun(Crawler $crawler): void
    {
        $active = $this->activeRun($crawler);

        if ($active !== null) {
            throw CrawlerRunAlreadyActive::forCrawler($crawler, $active);
        }
    }

    private function activeRun(Crawler $crawler): ?Run
    {
        return Run::query()
            ->where('crawler_id', $crawler->id)
            ->where('status', RunStatus::Running)
            ->latest('id')
            ->first();
    }

    private function resumableRun(Crawler $crawler): ?Run
    {
        return Run::query()
            ->where('crawler_id', $crawler->id)
            ->where('status', RunStatus::Paused)
            ->latest('id')
            ->first();
    }

    private function dispatch(Crawler $crawler, ?CrawlerMode $mode, ?CarbonInterface $from, ?CarbonInterface $to): void
    {
        $resolvedMode = $mode ?? $crawler->mode;
        [$from, $to] = $this->resolveWindow($crawler, $resolvedMode, $from, $to);

        CrawlRunJob::dispatch(
            crawlerId: $crawler->id,
            mode: $resolvedMode,
            executionLane: $crawler->execution_lane,
            from: $from?->toIso8601String(),
            to: $to?->toIso8601String(),
        );
    }

    /**
     * ЕДИНСТВЕННАЯ точка резолва окна archive-прогона (P3.8, Implementation Rules п. 1/5): явный
     * `$from` (override от команды/будущего вызывающего) побеждает безусловно; иначе окно читается
     * ЧЕРЕЗ КОНТРАКТ `HasArchiveWindow` (не `instanceof EisSearchFilter` — вторая площадка обязана
     * уметь участвовать); ни override, ни профильное окно — отказ, а не молчаливый дефолт (пустой
     * `$from`/`$to` дошёл бы до `CrawlRunJob::handle()` и упал бы ТАМ, уже после создания строки
     * прогона). Fresh/delta режим окно не резолвит вовсе — `CrawlRunJob` его для них не читает.
     *
     * @return array{0: ?CarbonInterface, 1: ?CarbonInterface}
     */
    private function resolveWindow(
        Crawler $crawler,
        CrawlerMode $mode,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
    ): array {
        if ($mode !== CrawlerMode::Archive || $from !== null) {
            return [$from, $to];
        }

        $filter = $crawler->filter;

        if ($filter instanceof HasArchiveWindow
            && $filter->archiveWindowFrom() !== null
            && $filter->archiveWindowTo() !== null
        ) {
            return [$filter->archiveWindowFrom(), $filter->archiveWindowTo()];
        }

        throw ArchiveWindowMissing::forCrawler($crawler);
    }
}
