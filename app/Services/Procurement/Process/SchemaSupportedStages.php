<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;

/**
 * Stage/owner tuples текущего baseline (execution#schema-staging). Обязан совпадать со stage CHECK
 * work/intents/reservations/handler_holds и owner tuple CHECK. Это не HandlerActivation: наличие
 * tuple не активирует handler. Unsupported stage отвергается до INSERT, а не хранится с NULL owner.
 */
final class SchemaSupportedStages
{
    /** @var array<string, array{column: string, subject: SubjectKind}> */
    private const array OWNERS = [
        'listing' => ['column' => 'crawl_block_id', 'subject' => SubjectKind::CrawlBlock],
        'detail' => ['column' => 'procurement_id', 'subject' => SubjectKind::Procurement],
        'status_refresh' => ['column' => 'procurement_id', 'subject' => SubjectKind::Procurement],
        'download' => ['column' => 'document_id', 'subject' => SubjectKind::Document],
    ];

    /** Все concrete owner columns work: ровно одна непустая на строку. */
    public const array OWNER_COLUMNS = [
        'crawl_block_id', 'procurement_id', 'document_id', 'document_version_id', 'analysis_window_id', 'analysis_run_id',
    ];

    /** @return list<StageCode> */
    public static function stages(): array
    {
        return array_map(static fn (string $stage): StageCode => StageCode::from($stage), array_keys(self::OWNERS));
    }

    public static function supports(StageCode $stage): bool
    {
        return isset(self::OWNERS[$stage->value]);
    }

    /** Отвергает unsupported stage до любой записи authority/work. */
    public static function ensureSupported(StageCode $stage): void
    {
        self::owner($stage);
    }

    public static function ownerColumn(StageCode $stage): string
    {
        return self::owner($stage)['column'];
    }

    public static function subject(StageCode $stage): SubjectKind
    {
        return self::owner($stage)['subject'];
    }

    /** @return array{column: string, subject: SubjectKind} */
    private static function owner(StageCode $stage): array
    {
        return self::OWNERS[$stage->value]
            ?? throw new \InvalidArgumentException('Stage is not schema-supported by the current baseline: '.$stage->value);
    }
}
