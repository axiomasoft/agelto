<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Models\Procurement\Procurement;
use App\Services\Crawler\Modes\DetailAnalysisProcessor;
use App\Services\Document\PipelineDispatcher;
use App\Services\Procurement\DetailRefetchSelector;
use Illuminate\Console\Command;
use Throwable;

/**
 * Тонкая точка входа стадии детального анализа: явная закупка по id либо весь due-набор
 * `DetailRefetchSelector` (`02-crawling.md` §5.2). Ошибка одной закупки не прерывает
 * обработку остальных (`DetailAnalysisProcessor::run()` сам не бросает исключений наружу —
 * try/catch здесь на случай непредвиденного сбоя моста/резолва конкретной закупки).
 */
final class FetchDetails extends Command
{
    protected $signature = 'crawler:fetch-details {procurement? : ID закупки (без аргумента — все закупки, due по DetailRefetchSelector)}';

    protected $description = 'Снимает детальную карточку закупки(-ок): оркестратор вкладок + мост в пайплайн документов';

    public function handle(DetailAnalysisProcessor $processor, DetailRefetchSelector $selector, PipelineDispatcher $dispatcher): int
    {
        $identifier = $this->argument('procurement');

        if ($identifier !== null) {
            $procurement = Procurement::query()->find((int) $identifier);

            if ($procurement === null) {
                $this->error("Закупка #{$identifier} не найдена.");

                return self::FAILURE;
            }

            $procurements = [$procurement];
        } else {
            $procurements = $selector->due();
        }

        $processed = 0;
        $hasFailure = false;

        foreach ($procurements as $procurement) {
            try {
                $processor->run(procurement: $procurement);
                $dispatcher->dispatch(procurement: $procurement);
                $processed++;
            } catch (Throwable $exception) {
                $hasFailure = true;
                $this->error("Закупка #{$procurement->id}: {$exception->getMessage()}");
            }
        }

        $this->info("Детальный слой снят для {$processed} закупок(и).");

        return $hasFailure ? self::FAILURE : self::SUCCESS;
    }
}
