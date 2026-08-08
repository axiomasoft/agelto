<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Enums\Procurement\Crawler\CrawlStage;
use App\Jobs\Procurement\FetchProcurementDetailJob;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\CrawlSettingsResolver;
use App\Services\Procurement\DetailDispatcher;
use Illuminate\Console\Command;

/**
 * Тонкая точка входа стадии детального анализа: явная закупка по id либо ограниченная due-пачка.
 * Снятие карточки и мост документов выполняет `FetchProcurementDetailJob` на `crawl_detail`.
 */
final class FetchDetails extends Command
{
    protected $signature = 'crawler:fetch-details {procurement? : ID закупки (без аргумента — ограниченная due-пачка)}';

    protected $description = 'Ставит детальные карточки закупок в очередь: ограниченная due-пачка или одна закупка';

    public function handle(DetailDispatcher $dispatcher, CrawlSettingsResolver $settings): int
    {
        $identifier = $this->argument('procurement');

        if ($identifier !== null) {
            $procurement = Procurement::query()->find((int) $identifier);

            if ($procurement === null) {
                $this->error("Закупка #{$identifier} не найдена.");

                return self::FAILURE;
            }

            FetchProcurementDetailJob::dispatch(procurementId: $procurement->id)->afterCommit();
            $this->info("Закупка #{$procurement->id} поставлена в очередь crawl_detail.");

            return self::SUCCESS;
        }

        $limit = $settings->defaultForStage(stage: CrawlStage::Detail)->dispatchBatchLimit;

        if ($limit === null) {
            $this->error('Не задан лимит пачки детальной стадии.');

            return self::FAILURE;
        }

        $dispatched = $dispatcher->dispatchDue(limit: $limit);

        $this->info("В очередь crawl_detail поставлено {$dispatched} закупок(и).");

        return self::SUCCESS;
    }
}
