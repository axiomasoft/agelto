<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Document\PreparedBatch;
use App\Dto\Procurement\Document\PreparedDocument;
use App\Dto\Procurement\Document\PrepareOcrCheckpoint;
use App\Dto\Procurement\Document\PreparePage;
use App\Dto\Procurement\Document\PrepareRange;
use App\Dto\Procurement\Document\PrepareReservations;
use App\Dto\Procurement\Document\VerifiedArtifact;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\MissingParts;
use App\Dto\Procurement\Process\PrepareInput;
use App\Dto\Procurement\Process\RetryAt;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\StagePartial;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Document\PageTextStatus;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\DeferralReason;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Exceptions\Procurement\Prepare\PreparationRecipeMismatch;
use App\Exceptions\Procurement\Prepare\ToolCleanupUnconfirmed;
use App\Exceptions\Procurement\Prepare\ToolFailed;
use App\Exceptions\Procurement\Prepare\ToolUnavailable;
use App\Repositories\Procurement\Document\PrepareSourceRepository;
use App\Repositories\Procurement\Document\PrepareStorageRepository;
use App\Services\Procurement\Prepare\PdfPreparationTools;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\Contracts\StageHandler;
use Illuminate\Support\Facades\Storage;

/** One whole-page batch per delivery, one guarded durable checkpoint, no legacy state writers. */
final readonly class PrepareHandler implements StageHandler
{
    public function __construct(private PreparePolicy $policy, private PdfPreparationTools $tools,
        private PrepareSourceRepository $sources, private PrepareStorageRepository $storage,
        private BlobStore $blobs, private ArtifactGarbageCollector $gc, private ProcessClock $clock, private PrepareWorkspace $workspace) {}

    public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
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
            $in = Storage::disk($source->blob->disk)->readStream($source->blob->objectKey);
            $out = fopen($path, 'xb');
            if (! is_resource($in) || ! is_resource($out)) {
                throw new ArtifactUnavailable('Preparation source unreadable.');
            }
            try {
                $hash = hash_init('sha256');
                $bytes = 0;
                while (! feof($in)) {
                    $execution->tick();
                    if ($this->clock->monotonicNs() / 1e9 >= $setupDeadline) {
                        throw new ToolUnavailable('prepare_setup_deadline');
                    }
                    $part = fread($in, 65536);
                    if ($part === false) {
                        throw new ArtifactUnavailable('Preparation read failed.');
                    }
                    $bytes += strlen($part);
                    if ($bytes > $source->blob->sizeBytes || $bytes > $r->workspace->byteUpperBound) {
                        throw new ArtifactUnavailable('Preparation source exceeded reserved bytes.');
                    }
                    hash_update($hash, $part);
                    if (fwrite($out, $part) !== strlen($part)) {
                        throw new ArtifactUnavailable('Preparation write failed.');
                    }
                }
                if ($bytes !== $source->blob->sizeBytes || hash_final($hash) !== $source->blob->hash->hex) {
                    throw new ArtifactUnavailable('Preparation source verification failed.');
                }
            } finally {
                fclose($in);
                fclose($out);
            }
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
                $pending = $progress->pending;
                $first = array_shift($pending);
                $range = new PrepareRange($first->start, min($first->end, $first->start + $progress->nextBatchSize), $first->failures);
                if ($range->end < $first->end) {
                    array_unshift($pending, new PrepareRange($range->end, $first->end, $first->failures));
                }
                $batches = $progress->batches;
                $failed = $progress->failed;
                $next = $progress->nextBatchSize;
                try {
                    $batch = $this->tools->batch($path, $directory, $range, $execution, $this->clock->monotonicNs() / 1e9 + $this->policy->batchSeconds);
                    if (count($batch->pages) !== $range->length() || $batch->pages[0]->page !== $range->start + 1) {
                        throw new \LogicException('Tool returned incorrect source page mapping.');
                    }
                    $artifact = $this->persist($batch->anchoredText, $r, $execution, $deadline);
                    $batches[] = new PreparedBatch($artifact, $batch->pages);
                    $next = max(1, min(20, (int) floor(150 / ($batch->seconds / $range->length()))));
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
            if (! $progress->coverage()->complete()) {
                return new StageBlocked(BlockedReason::DocumentQuality);
            }
            $batches = $progress->batches;
            usort($batches, fn (PreparedBatch $a, PreparedBatch $b): int => $a->pages[0]->page <=> $b->pages[0]->page);
            $text = '';
            foreach ($batches as $batch) {
                $a = $batch->artifact;
                $this->blobs->verify($a->reservation, $a->hash, $a->sizeBytes, $execution);
                if (strlen($text) + $a->sizeBytes > $this->policy->artifactBytes) {
                    return new StageBlocked(BlockedReason::DocumentQuality);
                }
                $text .= Storage::disk($a->reservation->disk)->get($a->reservation->objectKey());
            }
            $artifact = $this->persist($text, $r, $execution, $deadline);
            $keepOutput = true;

            return new StageSucceeded(new PreparedDocument($source->head, $progress, $artifact));
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
            if ($this->clock->monotonicNs() / 1e9 >= $deadline) {
                throw new ToolUnavailable('prepare_persistence_deadline');
            }

            return $artifact;
        } finally {
            fclose($stream);
        }
    }
}
