<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Document\Prepare\PreparedBatch;
use App\Dto\Procurement\Document\Prepare\PreparedDocument;
use App\Dto\Procurement\Document\Prepare\PrepareOcrCheckpoint;
use App\Dto\Procurement\Document\Prepare\PreparePage;
use App\Dto\Procurement\Document\Prepare\PrepareRange;
use App\Dto\Procurement\Document\Prepare\PrepareReservations;
use App\Dto\Procurement\Document\Prepare\PrepareSource;
use App\Dto\Procurement\Document\Storage\StorageReservationRequest;
use App\Dto\Procurement\Document\Storage\VerifiedArtifact;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Inputs\PrepareInput;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Outcomes\StageBlocked;
use App\Dto\Procurement\Process\Outcomes\StageDeferred;
use App\Dto\Procurement\Process\Outcomes\StageOutcome;
use App\Dto\Procurement\Process\Outcomes\StagePartial;
use App\Dto\Procurement\Process\Outcomes\StageSucceeded;
use App\Dto\Procurement\Process\Primitives\Duration;
use App\Dto\Procurement\Process\Primitives\MissingParts;
use App\Dto\Procurement\Process\Primitives\RetryAt;
use App\Enums\Procurement\Document\PageTextStatus;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Enums\Procurement\Process\Outcomes\DeferralReason;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Exceptions\Procurement\Prepare\PreparationRecipeMismatch;
use App\Exceptions\Procurement\Prepare\ToolCleanupUnconfirmed;
use App\Exceptions\Procurement\Prepare\ToolFailed;
use App\Exceptions\Procurement\Prepare\ToolUnavailable;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Repositories\Procurement\Document\PrepareSourceRepository;
use App\Repositories\Procurement\Document\PrepareStorageRepository;
use App\Services\Procurement\Prepare\Tools\PdfPreparationTools;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Support\Streams\BoundedStreamException;
use App\Support\Streams\BoundedStreamHasher;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

/** One whole-page batch per delivery, one guarded durable checkpoint, no legacy state writers. */
final readonly class PrepareHandler implements StageHandler
{
    public function __construct(private PreparePolicy $policy, private PdfPreparationTools $tools,
        private PrepareSourceRepository $sources, private PrepareStorageRepository $storage,
        private BlobStore $blobs, private ArtifactGarbageCollector $gc, private ProcessClock $clock, private PrepareWorkspace $workspace) {}

    public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
    {
        try {
            return $this->prepare($input, $execution);
        } catch (FilesystemException $failure) {
            throw new DependencyUnavailable('storage', $failure);
        }
    }

    private function prepare(WorkInput $input, ExecutionContext $execution): StageOutcome
    {
        if (! $input instanceof PrepareInput) {
            throw new \InvalidArgumentException('Prepare input required.');
        }
        if ($input->preparationRecipeHash != $this->policy->hash) {
            return new StageBlocked(BlockedReason::PreparationRecipeMismatch);
        }
        $execution->tick();
        $progress = $execution->lease()->checkpoint->stageProgress ?? new PrepareOcrCheckpoint;
        if (! $progress instanceof PrepareOcrCheckpoint) {
            throw new \InvalidArgumentException('Wrong preparation checkpoint.');
        }
        $source = $this->sources->get($input->documentVersionId);
        if ($source->mime !== 'application/pdf') {
            return new StageBlocked(BlockedReason::PolicyReview);
        }
        if ($progress->sourceHash !== null && ($progress->sourceHash != $source->blob->hash || $progress->recipeHash != $input->preparationRecipeHash)) {
            return new StageBlocked(BlockedReason::PreparationRecipeMismatch);
        }
        if ($progress->sourceHash !== null && $progress->pending === [] && ! $progress->coverage()->complete()) {
            return new StageBlocked(BlockedReason::DocumentQuality);
        }
        // Includes setup, probes, all tools and persistence. Never add a fresh 200 seconds after setup.
        if (! $execution->canStart(Duration::seconds($this->policy->chainSeconds))
            || $execution->remainingSlice()->milliseconds < ($this->policy->chainSeconds + $this->policy->reserveSeconds) * 1000) {
            return new StageDeferred(DeferralReason::SliceExhausted, new RetryAt($this->clock->dbNow()->value->modify('+1 second')));
        }
        $deadline = $this->clock->monotonicNs() / 1e9 + $this->policy->chainSeconds;
        $setupDeadline = $this->clock->monotonicNs() / 1e9 + $this->policy->setupSeconds;
        $r = $this->storage->reserve($execution->lease());
        if ($r instanceof StageBlocked) {
            return $r;
        }
        if ($r === null) {
            return new StageDeferred(DeferralReason::StorageUnavailable, new RetryAt($this->clock->dbNow()->value->modify('+30 seconds')));
        }
        $keepOutput = false;
        $directory = null;
        try {
            $directory = $this->workspace->open($r->workspace);
            $path = $directory.'/source.pdf';
            $this->stageSourcePdf($source, $r->workspace, $path, $execution, $setupDeadline);
            try {
                $count = $this->tools->pageCount($path, $directory, $execution, $setupDeadline);
            } catch (ToolFailed) {
                return new StageBlocked($this->clock->monotonicNs() / 1e9 >= $setupDeadline ? BlockedReason::ConverterPolicyIncompatible : BlockedReason::PolicyReview);
            }
            if ($this->clock->monotonicNs() / 1e9 + $this->policy->batchSeconds + $this->policy->persistenceSeconds > $deadline) {
                return new StageBlocked(BlockedReason::ConverterPolicyIncompatible);
            }
            if ($progress->sourceHash !== null && $count !== $progress->originalPages) {
                return new StageBlocked(BlockedReason::PreparationRecipeMismatch);
            }
            if ($progress->sourceHash === null) {
                $progress = new PrepareOcrCheckpoint($source->blob->hash, $input->preparationRecipeHash, $count, min($count, $this->policy->maxPages),
                    [new PrepareRange(0, min($count, $this->policy->maxPages))]);
                $execution->checkpoint($progress);
            }
            if ($progress->pending !== []) {
                return $this->processNextBatch($progress, $path, $directory, $r, $execution, $deadline, $keepOutput);
            }
            if (! $progress->coverage()->complete()) {
                return new StageBlocked(BlockedReason::DocumentQuality);
            }

            return $this->assembleFinalDocument($source, $progress, $r, $execution, $deadline, $keepOutput);
        } catch (ToolCleanupUnconfirmed) {
            return new StageBlocked(BlockedReason::ConverterPolicyIncompatible);
        } catch (PreparationRecipeMismatch) {
            return new StageBlocked(BlockedReason::PreparationRecipeMismatch);
        } catch (ArtifactUnavailable) {
            return new StageDeferred(DeferralReason::StorageUnavailable, new RetryAt($this->clock->dbNow()->value->modify('+30 seconds')));
        } catch (ToolUnavailable) {
            return new StageDeferred(DeferralReason::ConverterUnavailable, new RetryAt($this->clock->dbNow()->value->modify('+30 seconds')));
        } finally {
            if ($directory !== null) {
                $this->workspace->finish($r->workspace);
            }
            $this->gc->cleanup($r->spool);
            if (! $keepOutput) {
                $this->gc->cleanup($r->output);
            }
        }
    }

    private function stageSourcePdf(PrepareSource $source, StorageReservationRequest $workspace, string $path, ExecutionContext $execution, float $setupDeadline): void
    {
        $in = Storage::disk($source->blob->disk)->readStream($source->blob->objectKey);
        $out = fopen($path, 'xb');
        if (! is_resource($in) || ! is_resource($out)) {
            throw new ArtifactUnavailable('Preparation source unreadable.');
        }
        try {
            $hashed = BoundedStreamHasher::process(
                source: $in,
                maxBytes: min($source->blob->sizeBytes, $workspace->byteUpperBound),
                sink: $out,
                chunkSize: 65536,
                onTick: function () use ($execution, $setupDeadline): void {
                    $execution->tick();
                    if ($this->clock->monotonicNs() / 1e9 >= $setupDeadline) {
                        throw new ToolUnavailable('prepare_setup_deadline');
                    }
                },
            );
            if ($hashed->bytes !== $source->blob->sizeBytes || $hashed->sha256 !== $source->blob->hash->hex) {
                throw new ArtifactUnavailable('Preparation source verification failed.');
            }
        } catch (BoundedStreamException $exception) {
            throw match ($exception->reason) {
                BoundedStreamException::REASON_OVERFLOW => new ArtifactUnavailable('Preparation source exceeded reserved bytes.', previous: $exception),
                BoundedStreamException::REASON_WRITE => new ArtifactUnavailable('Preparation write failed.', previous: $exception),
                default => new ArtifactUnavailable('Preparation read failed.', previous: $exception),
            };
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function processNextBatch(
        PrepareOcrCheckpoint $progress,
        string $path,
        string $directory,
        PrepareReservations $r,
        ExecutionContext $execution,
        float $deadline,
        bool &$keepOutput,
    ): StagePartial {
        $pending = $progress->pending;
        $first = array_shift($pending);
        if ($first === null) {
            throw new \LogicException('Expected non-empty pending batch queue.');
        }
        $range = new PrepareRange($first->start, min($first->end, $first->start + $progress->nextBatchSize), $first->failures);
        if ($range->end < $first->end) {
            array_unshift($pending, new PrepareRange($range->end, $first->end, $first->failures));
        }
        $batches = $progress->batches;
        $failed = $progress->failed;
        $next = $progress->nextBatchSize;
        try {
            $batch = $this->tools->batch($path, $directory, $range, $execution, min($deadline - $this->policy->persistenceSeconds, $this->clock->monotonicNs() / 1e9 + $this->policy->batchSeconds));
            if (count($batch->pages) !== $range->length() || $batch->pages[0]->page !== $range->start + 1) {
                throw new \LogicException('Tool returned incorrect source page mapping.');
            }
            $artifact = $this->persist($batch->anchoredText, $r, $execution, $deadline);
            $batches[] = new PreparedBatch($artifact, $batch->pages);
            $next = max(1, min(20, (int) floor(150 / (max(0.001, $batch->seconds) / $range->length()))));
            $keepOutput = true;
        } catch (ToolFailed $e) {
            if ($range->length() > 1) {
                $mid = $range->start + intdiv($range->length(), 2);
                array_unshift($pending, new PrepareRange($range->start, $mid), new PrepareRange($mid, $range->end));
            } elseif ($range->failures === 0) {
                array_unshift($pending, new PrepareRange($range->start, $range->end, 1));
            } else {
                $failed[] = new PreparePage($range->start + 1, PageTextStatus::Failed, $e->getMessage());
            }
        }
        $progress = new PrepareOcrCheckpoint($progress->sourceHash, $progress->recipeHash, $progress->originalPages, $progress->pageLimit, $pending, $batches, $failed, $next);
        $execution->checkpoint($progress);

        // Final representation is built on another delivery; the accepted batch is already durable.
        return new StagePartial($progress, new MissingParts(['prepare_manifest']));
    }

    private function assembleFinalDocument(
        PrepareSource $source,
        PrepareOcrCheckpoint $progress,
        PrepareReservations $r,
        ExecutionContext $execution,
        float $deadline,
        bool &$keepOutput,
    ): StageOutcome {
        $batches = $progress->batches;
        usort($batches, fn (PreparedBatch $a, PreparedBatch $b): int => $a->pages[0]->page <=> $b->pages[0]->page);
        $text = '';
        foreach ($batches as $batch) {
            $a = $batch->artifact;
            $this->blobs->verify($a->reservation, $a->hash, $a->sizeBytes, $execution);
            if (strlen($text) + $a->sizeBytes > $this->policy->artifactBytes) {
                return new StageBlocked(BlockedReason::DocumentQuality);
            }
            $bytes = Storage::disk($a->reservation->disk)->get($a->reservation->objectKey());
            if (! is_string($bytes) || strlen($bytes) !== $a->sizeBytes || hash('sha256', $bytes) !== $a->hash->hex) {
                throw new ArtifactUnavailable('Prepared batch changed or became unavailable during assembly.');
            }
            $text .= $bytes;
        }
        $artifact = $this->persist($text, $r, $execution, $deadline);
        $keepOutput = true;

        return new StageSucceeded(new PreparedDocument($source->head, $progress, $artifact));
    }

    private function persist(string $text, PrepareReservations $r, ExecutionContext $ctx, float $deadline): VerifiedArtifact
    {
        if ($this->clock->monotonicNs() / 1e9 >= $deadline) {
            throw new ToolUnavailable('prepare_persistence_deadline');
        }
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new ArtifactUnavailable('Prepare spool unavailable.');
        }
        try {
            fwrite($stream, $text);
            rewind($stream);

            $artifact = $this->blobs->put($r->output, $stream, $r->spool, $ctx);
            $this->blobs->verifyExisting($artifact, $ctx);
            if ($this->clock->monotonicNs() / 1e9 >= $deadline) {
                throw new ToolUnavailable('prepare_persistence_deadline');
            }

            return $artifact;
        } finally {
            fclose($stream);
        }
    }
}
