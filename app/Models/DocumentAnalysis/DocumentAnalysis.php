<?php

declare(strict_types=1);

namespace App\Models\DocumentAnalysis;

use App\Enums\AnalysisStatus;
use App\Models\Crawler\Link;
use App\Models\Crawler\LinkAttachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class DocumentAnalysis extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const COLLECTION_DOCUMENT_SOURCE = 'document-source';

    public const COLLECTION_DOCUMENT_PREPARED = 'document-prepared';

    public string $mediaPath = 'document-analysis';

    protected $table = 'plt_document_analysis';

    protected $fillable = [
        'model_type',
        'model_id',
        'parent_id',
        'name',
        'extension',
        'file_fingerprint',
        'source',
        'gemini_cache_id',
        'gemini_rules_hash',
        'gemini_cache_expires_at',
        'options',
        'status',
        'completed_at',
        'attempts',
        'last_error',
        'last_error_at',
        'next_retry_at',
        'failed_permanently',
        'locked_by',
        'locked_until',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Владелец анализа (LinkAttachment и др.). Колонки: model_type, model_id.
     */
    public function documentAnalysable(): MorphTo
    {
        return $this->morphTo(name: 'model');
    }

    /**
     * Возвращает владельца анализа (полиморфная связь по model_type, model_id).
     */
    public function getDocumentAnalysable(): Model|Media|null
    {
        return $this->model;
    }

    public function responses(): HasMany
    {
        return $this->hasMany(DocumentAnalysisResponse::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(DocumentAnalysisLog::class);
    }

    /**
     * Документ для анализа: приоритет у подготовленного (document-prepared), иначе исходный (document-source).
     */
    public function getDocumentForAnalysis(): ?Media
    {
        return $this->getFirstMedia(self::COLLECTION_DOCUMENT_PREPARED)
            ?? $this->getFirstMedia(self::COLLECTION_DOCUMENT_SOURCE);
    }

    /**
     * Проверяет, можно ли использовать текущий кэш для данного файла и правил
     */
    public function isCacheValid(string $fingerprint, string $rulesHash): bool
    {
        return $this->gemini_cache_id
            && $this->file_fingerprint === $fingerprint
            && $this->gemini_rules_hash === $rulesHash
            && $this->gemini_cache_expires_at?->isFuture();
    }

    /**
     * Переход в новый статус с записью в лог.
     *
     * @param  array<string, mixed>|null  $content
     */
    public function transitionStatus(
        AnalysisStatus $status,
        ?string $message = null,
        ?string $trace = null,
        ?array $content = null,
    ): void {
        $attributes = ['status' => $status];

        if (in_array($status, [
            AnalysisStatus::Error,
            AnalysisStatus::PrimaryAnalysisFailed,
            AnalysisStatus::EstimateAnalysisFailed,
        ], true) && $message !== null) {
            $attributes['last_error'] = $message;
        }

        $this->update($attributes);

        $this->logs()->create([
            'status' => $status,
            'message' => $message,
            'trace' => $trace,
            'content' => $content,
        ]);

        // Держим агрегат-статус ссылки в актуальном состоянии после смены статуса анализа.
        $this->resolveLink()?->recomputeStatusFromAnalyses();
    }

    /**
     * Ссылка-владелец анализа (через LinkAttachment), если анализ привязан к вложению.
     */
    public function resolveLink(): ?Link
    {
        $owner = $this->documentAnalysable;

        return $owner instanceof LinkAttachment ? $owner->link : null;
    }

    /**
     * Фиксация провала стадии: гранулярный статус + лог, инкремент попыток, backoff для
     * повторного диспатча командой retry и перевод в dead-letter при исчерпании попыток.
     */
    public function recordFailure(AnalysisStatus $status, string $message, ?string $trace = null, int $maxAttempts = 5): void
    {
        $attempts = $this->attempts + 1;
        $isPermanent = $attempts >= $maxAttempts;
        // Экспоненциальный backoff с потолком ~4 часа.
        $delayMinutes = (int) min(2 ** $attempts, 240);

        $this->forceFill([
            'attempts' => $attempts,
            'last_error_at' => now(),
            'next_retry_at' => $isPermanent ? null : now()->addMinutes($delayMinutes),
            'failed_permanently' => $isPermanent,
        ])->save();

        // transitionStatus сменит статус, запишет лог и пересчитает агрегат-статус ссылки.
        $this->transitionStatus(status: $status, message: $message, trace: $trace);
    }

    /**
     * Атомарный захват записи на время обработки стадии (анти-дубль при крашах/ретраях).
     * Возвращает true, если лиза свободна или истекла и успешно взята.
     */
    public function acquireLease(string $owner, int $ttlSeconds = 600): bool
    {
        $lockedUntil = now()->addSeconds($ttlSeconds);

        $acquired = self::query()
            ->whereKey($this->getKey())
            ->where(fn ($query) => $query
                ->whereNull('locked_until')
                ->orWhere('locked_until', '<', now())
            )
            ->update([
                'locked_by' => $owner,
                'locked_until' => $lockedUntil,
            ]);

        if ($acquired === 1) {
            $this->forceFill(['locked_by' => $owner, 'locked_until' => $lockedUntil]);

            return true;
        }

        return false;
    }

    public function releaseLease(): void
    {
        self::query()->whereKey($this->getKey())->update(['locked_by' => null, 'locked_until' => null]);
        $this->forceFill(['locked_by' => null, 'locked_until' => null]);
    }

    /** В активной обработке: выполняется или ждёт следующей стадии (не терминал и не провал). */
    public function isProcessing(): bool
    {
        return $this->status->isInProgress() || $this->status->isAwaiting();
    }

    /** Терминальный успех. */
    public function isFinished(): bool
    {
        return $this->status->isTerminal();
    }

    protected function casts(): array
    {
        return [
            'status' => AnalysisStatus::class,
            'options' => 'array',
            'completed_at' => 'datetime',
            'gemini_cache_expires_at' => 'datetime',
            'attempts' => 'integer',
            'last_error_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'failed_permanently' => 'boolean',
            'locked_until' => 'datetime',
        ];
    }
}
