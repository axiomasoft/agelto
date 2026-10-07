<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler;

use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceStage;

/**
 * Отказ, о котором вызывающий (CSV-обход, обработка карточки) сообщает в единую точку
 * `SourceFailureReporter`. Вызывающий только описывает факт; учёт, журнал и лог — не его дело.
 */
final readonly class SourceFailure
{
    /**
     * @param  array<string, mixed>|null  $cursor
     * @param  array<string, mixed>|null  $requestParams
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public int $platformId,
        public SourceStage $stage,
        public SourceFailureKind $kind,
        public string $detail,
        public ?int $crawlerId = null,
        public ?int $runId = null,
        public ?int $fetchId = null,
        public ?int $httpStatus = null,
        public ?array $cursor = null,
        public ?array $requestParams = null,
        public array $context = [],
    ) {}
}
