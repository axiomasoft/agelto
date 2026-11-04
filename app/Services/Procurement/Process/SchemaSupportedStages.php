<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;

/**
 * Поддерживаемые stage/owner tuples. Набор обязан совпадать со stage CHECK
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
        'prepare' => ['column' => 'document_version_id', 'subject' => SubjectKind::DocumentVersion],
        'screening' => ['column' => 'document_version_id', 'subject' => SubjectKind::DocumentVersion],
        'extraction' => ['column' => 'analysis_window_id', 'subject' => SubjectKind::AnalysisWindow],
        'publication' => ['column' => 'analysis_run_id', 'subject' => SubjectKind::AnalysisRun],
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
        return in_array($stage, self::stages(), true);
    }

    /** Отвергает unsupported stage до любой записи authority/work. */
    public static function ensureSupported(StageCode $stage): void
    {
        // Schema membership assertion only; this never selects or activates a handler.
        self::supports($stage) ?: throw new \InvalidArgumentException('Stage is not schema-supported: '.$stage->value);
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
        return self::OWNERS[$stage->value];
    }
}
