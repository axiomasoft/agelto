<?php

declare(strict_types=1);

namespace App\Models\Crawler;

use App\Enums\Crawling\CrawlingStatus;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Models\Platform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Link extends Model
{
    // Указываем новое короткое имя таблицы
    protected $table = 'plt_crawler_links';

    protected $fillable = [
        'platform_id',
        'crawler_id',
        'crawler_run_id',
        'reg_number',
        'okved',
        'okved_additional',
        'detail_url',
        'subject',
        'published_at',
        'platform_updated_at',
        'submission_end_at',
        'status',
        'completed_at',
        'attempts',
        'last_error',
        'last_error_at',
        'next_retry_at',
        'failed_permanently',
    ];

    protected $casts = [
        'status' => CrawlingStatus::class,
        'published_at' => 'datetime',
        'platform_updated_at' => 'datetime',
        'submission_end_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_error_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'failed_permanently' => 'boolean',
        'attempts' => 'integer',
    ];

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function crawler(): BelongsTo
    {
        return $this->belongsTo(Crawler::class);
    }

    public function crawlerRun(): BelongsTo
    {
        return $this->belongsTo(CrawlerRun::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LinkAttachment::class);
    }

    /**
     * Пересчитывает агрегат-статус ссылки из статусов анализов её вложений (Фаза 1):
     * есть активные → PROCESSING; все терминальные → COMPLETED; есть перманентный провал → FAILED.
     */
    public function recomputeStatusFromAnalyses(): void
    {
        $attachmentIds = $this->attachments()->pluck('id');

        if ($attachmentIds->isEmpty()) {
            return;
        }

        $analyses = DocumentAnalysis::query()
            ->where('model_type', (new LinkAttachment)->getMorphClass())
            ->whereIn('model_id', $attachmentIds)
            ->get(['status', 'failed_permanently']);

        if ($analyses->isEmpty()) {
            return;
        }

        $hasActive = $analyses->contains(fn (DocumentAnalysis $a) => $a->status->isInProgress() || $a->status->isAwaiting());
        $allTerminal = $analyses->every(fn (DocumentAnalysis $a) => $a->status->isTerminal());
        $hasPermanentFail = $analyses->contains(fn (DocumentAnalysis $a) => $a->failed_permanently);

        $status = match (true) {
            $hasActive => CrawlingStatus::PROCESSING,
            $allTerminal => CrawlingStatus::COMPLETED,
            $hasPermanentFail => CrawlingStatus::FAILED,
            default => CrawlingStatus::PROCESSING,
        };

        if ($this->status !== $status) {
            $this->update(['status' => $status]);
        }
    }
}
