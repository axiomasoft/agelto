<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Crawler;

use App\Exceptions\Procurement\OperationFailed;
use App\Models\Procurement\Crawler\Crawler;

/**
 * Archive-профиль без окна дат (`StartCrawlerRunAction`, P3.8, D113) — отказ ДО диспетчеризации
 * `CrawlRunJob`: `LogicException` внутри `CrawlRunJob::handle()` остаётся инвариантом последней
 * инстанции, пользовательский отказ формирует ТОЛЬКО вызывающий (`research/04-p3-runs-design.md`
 * §6 п. 4).
 */
final class ArchiveWindowMissing extends OperationFailed
{
    public static function forCrawler(Crawler $crawler): self
    {
        return new self(sprintf(
            'У профиля «%s» (archive) не заполнено окно дат — заполните «Дата размещения с/по» '
            .'в форме профиля либо запустите обход из консоли с явными --from/--to.',
            $crawler->name,
        ));
    }

    private function __construct(string $message)
    {
        parent::__construct($message);
    }
}
