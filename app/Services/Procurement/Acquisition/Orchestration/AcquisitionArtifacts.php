<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition\Orchestration;

use App\Actions\Procurement\Crawler\StoreRawFetchArtifactAction;
use App\Dto\Procurement\Network\Http\DecodedTextBody;
use App\Dto\Procurement\Network\Outcome\TransportDeferred;
use App\Dto\Procurement\Network\Outcome\TransportOutcome;
use App\Dto\Procurement\Network\Outcome\TransportSucceeded;
use App\Dto\Procurement\Process\Outcomes\ArtifactRef;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Network\TrafficClass;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use Illuminate\Contracts\Config\Repository;

/** Immutable evidence for physical responses and pre-send input rejections. */
final readonly class AcquisitionArtifacts
{
    private string $disk;

    public function __construct(private StoreRawFetchArtifactAction $store, private Repository $config)
    {
        $disk = $config->get('acquisition.raw_disk', 's3');
        if (! is_string($disk) || $disk === '') {
            throw new \InvalidArgumentException('Invalid acquisition raw evidence disk.');
        }
        $this->disk = $disk;
    }

    public function capture(TransportOutcome $outcome, TrafficClass $traffic): void
    {
        $detail = in_array($traffic, [TrafficClass::Detail, TrafficClass::StatusRefresh], true);
        if ($detail && $this->config->get('acquisition.raw_html_retention_enabled') !== true) {
            return;
        }
        if ($outcome instanceof TransportSucceeded && $outcome->response->body instanceof DecodedTextBody) {
            $id = $outcome->physicalRequestId ?? throw new \LogicException('Missing physical response identity.');
            $body = $outcome->response->body;
        } elseif ($outcome instanceof TransportDeferred && $outcome->finalResponse !== null) {
            $id = $outcome->finalResponse->requestId;
            $body = $outcome->finalResponse->body;
        } else {
            return;
        }
        try {
            $gzip = $detail && $this->config->get('acquisition.raw_html_gzip') === true;
            $bytes = $gzip ? gzencode($body->utf8) : $body->utf8;
            if ($bytes === false) {
                throw new DependencyUnavailable('storage');
            }
            $disk = $detail ? $this->config->get('acquisition.raw_html_disk', $this->disk) : $this->disk;
            if (! is_string($disk) || $disk === '') {
                throw new \InvalidArgumentException('Invalid detail raw evidence disk.');
            }
            $this->store->execute($disk, 'raw/acquisition/'.$id->value,
                ['physical_request_id' => $id->value], $bytes, $gzip ? 'txt.gz' : 'txt');
        } catch (DependencyUnavailable $failure) {
            throw new DependencyUnavailable('storage', $failure);
        }
    }

    public function rejected(ProcessUuid $requestId, DecodedTextBody $body): ArtifactRef
    {
        try {
            $path = $this->store->execute($this->disk, 'raw/acquisition/'.$requestId->value,
                ['physical_request_id' => $requestId->value], $body->utf8, 'txt');

            return new ArtifactRef($this->disk, $path, ContentHash::of($body->utf8));
        } catch (DependencyUnavailable $failure) {
            throw new DependencyUnavailable('storage', $failure);
        }
    }

    public function rejectedInput(string $evidence): ArtifactRef
    {
        $hash = ContentHash::of($evidence);
        $path = $this->store->execute(disk: $this->disk, prefix: 'raw/acquisition/input',
            requestIdentity: ['input_sha256' => $hash->hex], contents: $evidence, extension: 'json');

        return new ArtifactRef($this->disk, $path, $hash);
    }
}
