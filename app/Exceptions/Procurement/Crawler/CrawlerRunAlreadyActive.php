<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Crawler;

use App\Exceptions\Procurement\OperationFailed;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;

/**
 * Профиль уже несёт `running`-прогон (`StartCrawlerRunAction`, P3.4) — второй запуск отклоняется
 * ДО диспетчеризации `CrawlRunJob`: `CrawlerRunManager::startOrResume()` сам по себе отказом не
 * является (вернул бы тот же прогон), отказ формирует вызывающий (`research/04-p3-runs-design.md`
 * §6 п. 4).
 */
final class CrawlerRunAlreadyActive extends OperationFailed
{
    public static function forCrawler(Crawler $crawler, Run $run): self
    {
        return new self(sprintf(
            'У профиля «%s» уже идёт прогон #%d — остановите или отмените его, прежде чем запускать новый.',
            $crawler->name,
            $run->id,
        ));
    }

    private function __construct(string $message)
    {
        parent::__construct($message);
    }
}
