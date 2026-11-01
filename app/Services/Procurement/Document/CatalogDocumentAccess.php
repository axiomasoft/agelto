<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Acquisition\FetchBlocked;
use App\Dto\Procurement\Document\DocumentFetchPlan;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Dto\Procurement\Process\DownloadInput;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\TrafficClass;
use App\Repositories\Procurement\Network\SourceCatalogRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use Illuminate\Support\Str;

/** Pure HTTP descriptor resolution; a signed-reference adapter may replace this narrow port. */
final readonly class CatalogDocumentAccess implements DocumentAccessResolver
{
    public function __construct(private SourceCatalogRepository $catalog) {}

    public function resolve(DownloadInput $input, ExecutionContext $execution, bool $refresh = false): DocumentFetchPlan|FetchBlocked
    {
        $row = LedgerRow::one("SELECT d.title,d.platform_id,d.access_descriptor::text AS descriptor,d.access_schema_version,
            encode(i.access_policy_hash,'hex') AS access_hash FROM prc_documents d JOIN prc_operation_intents i ON i.id=?
            WHERE d.id=? AND i.subject_kind='prc_documents' AND i.subject_id=d.id::text AND i.stage='download'",
            [$input->acquisitionIntentId->value, $input->documentId]);
        if ($row === null || $row->int('access_schema_version') !== 1 || $row->string('access_hash') !== $input->accessPolicyHash->hex) {
            throw new \LogicException('Document descriptor/input binding mismatch.');
        }
        if ($refresh) {
            // No guessed source endpoint or silent scraping fallback. Adapter owns signed refresh.
            return new FetchBlocked(NetworkBlockedReason::PolicyReview);
        }
        $descriptor = json_decode($row->string('descriptor'), true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($descriptor) || count($descriptor) !== 2 || ! isset($descriptor['url']) || ! is_string($descriptor['url'])
            || ! array_key_exists('expires_at', $descriptor) || ($descriptor['expires_at'] !== null && ! is_string($descriptor['expires_at']))) {
            throw new \InvalidArgumentException('Unsupported document descriptor schema.');
        }
        $url = new SafeUrl($descriptor['url']);
        $target = $this->catalog->targetForCall($row->int('platform_id'), null, 'download', $url->origin->value());
        if ($target === null) {
            return new FetchBlocked(NetworkBlockedReason::ConfigurationHold);
        }
        $policy = json_decode($target->string('endpoint_policy'), true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($policy) || ! isset($policy['profiles']) || ! is_array($policy['profiles'])) {
            throw new \LogicException('Download target lacks endpoint policy.');
        }
        $profiles = array_values(array_filter($policy['profiles'], static fn (mixed $p): bool => is_array($p) && ($p['response_kind'] ?? null) === 'binary'));
        if (count($profiles) !== 1) {
            throw new \LogicException('Download target requires exactly one binary profile.');
        }
        $profile = RequestProfile::fromArray($profiles[0]);

        return new DocumentFetchPlan(new OutboundRequestPlan(new ProcessUuid((string) Str::uuid()), new ProcessUuid($target->string('id')),
            'GET', $url, $profile, TrafficClass::Download, IdempotencyClass::ReadOnly), $row->string('title'));
    }
}
