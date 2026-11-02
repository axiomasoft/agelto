<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Repositories\Procurement\Ai\OutputStorageRepository;
use App\Services\Procurement\Document\BlobStore;
use Illuminate\Support\Facades\DB;
use League\Flysystem\FilesystemException;

/** Immutable output uses the same capacity, read-back verification and deletion gate as documents. */
final readonly class ValidatedOutputStore
{
    public function __construct(private OutputStorageRepository $storage, private BlobStore $blobs) {}

    public function put(string $canonical, ExecutionContext $execution): ArtifactRef
    {
        if (DB::transactionLevel() !== 0 || strlen($canonical) > 16_777_216 || $canonical === '') {
            throw new \LogicException('Validated output must be bounded and stored outside the DB transaction.');
        }
        [$output, $temporary] = $this->storage->reserve($execution->lease(), strlen($canonical));
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new DependencyUnavailable('storage');
        }
        try {
            if (fwrite($stream, $canonical) !== strlen($canonical) || ! rewind($stream)) {
                throw new DependencyUnavailable('storage');
            }
            $artifact = $this->blobs->put($output, $stream, $temporary, $execution);
            $this->blobs->verifyExisting($artifact, $execution);
            $blob = $this->storage->acquire($execution->lease(), $artifact);

            return new ArtifactRef($blob->disk, $blob->objectKey, $blob->hash);
        } catch (ArtifactUnavailable|FilesystemException $failure) {
            throw new DependencyUnavailable('storage', previous: $failure);
        } finally {
            fclose($stream);
        }
    }
}
