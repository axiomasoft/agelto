<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\DownloadInput;
use App\Dto\Procurement\Process\ListingInput;
use App\Dto\Procurement\Process\LockedSubject;
use App\Dto\Procurement\Process\StatusRefreshInput;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\SubjectKind;

/**
 * Composition seam subject locks (execution#subject-locks) для schema-supported stages текущего
 * baseline. Без I/O: набор реальных subject rows выводится из immutable typed input, их heads
 * repository читает сам, а решение input invalidation принимается уже по locked heads.
 * Будущие prepare/screening/extraction/publication добавляют ancestors своими пакетами.
 */
final readonly class SubjectLockResolver
{
    /** @return non-empty-list<SubjectKey> */
    public function subjects(WorkInput $input): array
    {
        return match (true) {
            $input instanceof ListingInput => [new SubjectKey(SubjectKind::CrawlBlock, $input->crawlBlockId)],
            $input instanceof DetailInput => [new SubjectKey(SubjectKind::Procurement, $input->procurementId)],
            $input instanceof StatusRefreshInput => [new SubjectKey(SubjectKind::Procurement, $input->procurementId)],
            $input instanceof DownloadInput => [new SubjectKey(SubjectKind::Document, $input->documentId)],
            default => throw new \InvalidArgumentException('Stage is not schema-supported by the current baseline: '.$input->stage()->value),
        };
    }

    /**
     * Текущий subject authority больше не соответствует immutable input work: работа подлежит
     * fenced supersede, а не исполнению. Listing — input_revision блока; detail/download —
     * expected head generation procurement/document. Status refresh сериализуется procurement
     * row; его cycle invalidation вводит acquisition contract (P2.16).
     *
     * @param  array<string, LockedSubject>  $locked  keyed by SubjectKey::key()
     */
    public function inputInvalidated(WorkInput $input, int $inputRevision, array $locked): bool
    {
        $owner = $locked[$this->subjects($input)[0]->key()]
            ?? throw new \LogicException('Owner subject must be locked before invalidation check.');

        return match (true) {
            $input instanceof ListingInput => $owner->inputRevision !== $inputRevision,
            $input instanceof DetailInput => $owner->headGeneration !== $input->expectedHeadGeneration,
            $input instanceof DownloadInput => $owner->headGeneration !== $input->expectedDocumentHeadGeneration,
            default => false,
        };
    }
}
