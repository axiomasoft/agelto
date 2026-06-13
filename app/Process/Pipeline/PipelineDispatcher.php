<?php

declare(strict_types=1);

namespace App\Process\Pipeline;

use App\Enums\Crawling\CrawlingStatus;
use App\Process\DocumentAnalysis\PrimaryAnalysisProcessor;
use App\Repositories\Crawling\CrawlingRepository;

/**
 * Конвейер пайплайна: надёжно и идемпотентно передаёт работу между стадиями.
 * Сейчас — мост Стадия 1 (Crawling) → Стадия 2 (DocumentPrepare), которого раньше не было (C1):
 * по свежим PENDING-ссылкам создаёт DocumentAnalysis для вложений без анализа и запускает цепочку.
 */
final class PipelineDispatcher
{
    public function __construct(
        private readonly CrawlingRepository $crawlingRepo,
        private readonly PrimaryAnalysisProcessor $primaryAnalysis,
    ) {}

    /**
     * @return int число задиспатченных вложений
     */
    public function dispatchPreparationForPendingLinks(int $linkLimit = 100): int
    {
        $links = $this->crawlingRepo->getPendingLinks($linkLimit);

        $dispatched = 0;

        foreach ($links as $link) {
            // Предзагружены только вложения без анализа — повторный запуск не плодит дубли.
            foreach ($link->attachments as $attachment) {
                $this->primaryAnalysis->run($attachment, ['url']);
                $dispatched++;
            }

            // Ссылка ушла в обработку — повторный скан её больше не подхватит.
            $this->crawlingRepo->updateStatus($link, CrawlingStatus::PROCESSING);
        }

        return $dispatched;
    }
}
