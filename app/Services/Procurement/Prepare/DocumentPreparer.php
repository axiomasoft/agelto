<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Actions\Procurement\Document\MarkDocumentUnprocessableAction;
use App\Enums\Procurement\Document\PreparationResult;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Contracts\Container\Container;

/**
 * Registry и inspector определяют единственный strategy до его запуска. Legacy strategy methods
 * still return their internal completion signal until their public contract is migrated; that
 * signal is not used for format selection and cannot fall through to another handler.
 */
final readonly class DocumentPreparer
{
    public function __construct(
        private Container $container,
        private DocumentFormatInspector $inspector,
        private MarkDocumentUnprocessableAction $markUnprocessable,
    ) {}

    public function prepare(Document $document, DocumentVersion $version, ?float $deadlineAt = null): PreparationResult
    {
        $evidence = $this->inspector->inspect($version);

        if ($evidence->reason !== null) {
            $this->markUnprocessable->execute($document, $evidence->reason, (string) $evidence->detail);

            return PreparationResult::Unprocessable;
        }

        if ($evidence->strategy === null) {
            throw new \LogicException('Распознанный формат документа должен иметь strategy.');
        }

        /** @var PreparationStrategy $strategy */
        $strategy = $this->container->make($evidence->strategy);

        if ($strategy instanceof ScanStrategy || $strategy instanceof ArchiveStrategy || $strategy instanceof GsfxStrategy) {
            return $strategy->process(document: $document, version: $version, deadlineAt: $deadlineAt);
        }

        return $strategy->process(document: $document, version: $version);
    }
}
