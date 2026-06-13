<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Enums\Crawling\CrawlingStatus;
use App\Models\Crawler\Link;
use App\Models\Crawler\LinkAttachment;
use App\Process\DocumentAnalysis\Jobs\PrimaryAnalysisJob;
use App\Process\DocumentPrepare\Jobs\PrepareDocumentJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

final class PipelineRetryFailedLinksCommand extends Command
{
    protected $signature = 'app:pipeline:retry-failed-links {--limit=50 : Максимум ссылок за один запуск}';

    protected $description = 'Повторно запускает обработку FAILED-ссылок, у которых истёк next_retry_at';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $links = Link::query()
            ->where('status', CrawlingStatus::FAILED)
            ->where(fn ($q) => $q
                ->whereNull('next_retry_at')
                ->orWhere('next_retry_at', '<=', now())
            )
            ->with('attachments')
            ->limit($limit)
            ->get();

        if ($links->isEmpty()) {
            $this->info('Нет ссылок для повторной обработки.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($links as $link) {
            /** @var Link $link */
            $link->update([
                'status' => CrawlingStatus::PENDING,
                'last_error' => null,
            ]);

            /** @var LinkAttachment $attachment */
            foreach ($link->attachments as $attachment) {
                $analysis = $attachment->documentAnalyses()
                    ->whereIn('status', [
                        AnalysisStatus::Error->value,
                        AnalysisStatus::PrimaryAnalysisFailed->value,
                        AnalysisStatus::EstimateAnalysisFailed->value,
                    ])
                    ->first();

                if ($analysis === null) {
                    continue;
                }

                Bus::chain([
                    new PrepareDocumentJob(documentAnalysisId: $analysis->id),
                    new PrimaryAnalysisJob(documentAnalysisId: $analysis->id),
                ])->afterCommit()->dispatch();

                $dispatched++;
            }
        }

        $this->info("Обработано ссылок: {$links->count()}, диспатчено jobs: {$dispatched}");

        return self::SUCCESS;
    }
}
