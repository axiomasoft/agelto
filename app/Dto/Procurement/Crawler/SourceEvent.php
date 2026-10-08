<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler;

use App\Enums\Procurement\Crawler\CrawlerEventCode;
use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Support\SecretRedactor;

/**
 * Событие источника: что записать в журнал и в лог. Одни и те же поля становятся колонками
 * `prc_source_incidents` и тегами лога (`tags()`), чтобы журнал и лог не расходились.
 */
final readonly class SourceEvent
{
    /**
     * @param  array<string, mixed>|null  $cursor
     * @param  array<string, mixed>|null  $requestParams
     * @param  array<string, mixed>  $context
     */
    public string $message;

    /** @var array<string, mixed>|null */
    public ?array $requestParams;

    /** @var array<string, mixed> */
    public array $context;

    /** @param array<string, mixed>|null $cursor
     * @param array<string, mixed>|null $requestParams
     * @param array<string, mixed> $context */
    public function __construct(
        public CrawlerEventCode $code,
        public int $platformId,
        string $message,
        public ?SourceStage $stage = null,
        public ?int $crawlerId = null,
        public ?int $runId = null,
        public ?int $fetchId = null,
        public ?SourceFailureKind $failureKind = null,
        public ?int $httpStatus = null,
        public ?array $cursor = null,
        ?array $requestParams = null,
        array $context = [],
        public ?string $actor = null,
    ) {
        $this->message = SecretRedactor::text($message);
        $this->requestParams = SecretRedactor::context($requestParams);
        $this->context = SecretRedactor::context($context) ?? [];
    }

    public static function forFailure(CrawlerEventCode $code, SourceFailure $failure, string $message, ?string $actor = null): self
    {
        return new self(
            code: $code,
            platformId: $failure->platformId,
            message: $message,
            stage: $failure->stage,
            crawlerId: $failure->crawlerId,
            runId: $failure->runId,
            fetchId: $failure->fetchId,
            failureKind: $failure->kind,
            httpStatus: $failure->httpStatus,
            cursor: $failure->cursor,
            requestParams: $failure->requestParams,
            context: $failure->context,
            actor: $actor,
        );
    }

    /** @return array<string, mixed>|null контекст журнала: `stage` + произвольный; `null` — пусто */
    public function incidentContext(): ?array
    {
        $context = array_filter(['stage' => $this->stage?->value] + $this->context, static fn (mixed $value): bool => $value !== null);

        return $context === [] ? null : $context;
    }

    /**
     * Теги лога (только непустые).
     *
     * @return array<string, mixed>
     */
    public function tags(?int $incidentId = null): array
    {
        return array_filter([
            'code' => $this->code->value,
            'incident_id' => $incidentId,
            'platform_id' => $this->platformId,
            'stage' => $this->stage?->value,
            'crawler_id' => $this->crawlerId,
            'run_id' => $this->runId,
            'fetch_id' => $this->fetchId,
            'failure_kind' => $this->failureKind?->value,
            'http_status' => $this->httpStatus,
            'cursor' => $this->cursor,
            'request_params' => $this->requestParams,
            'actor' => $this->actor,
        ] + $this->context, static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
