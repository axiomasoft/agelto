<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Crawler;

use App\Dto\Procurement\Acquisition\DetailProgress;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Platform\SourceIdentifierType;
use App\Dto\Procurement\Platform\SourceIdentifierValue;
use App\Dto\Procurement\Platform\SourceIdentity;
use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Platform\SnapshotConsistency;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Acquisition\DetailValueCodec;
use Illuminate\Support\Facades\DB;

final readonly class DetailSnapshotRepository
{
    public function __construct(private DetailValueCodec $codec, private ListingStoreRepository $listing) {}

    public function request(int $procurement, ProcessUuid $intent): DetailRequest
    {
        $row = LedgerRow::one('SELECT result_ref::text AS payload FROM prc_detail_snapshots WHERE procurement_id=? AND part_key=?', [$procurement, $intent->value.':request']);
        if ($row !== null) {
            $request = $this->codec->decode($row->string('payload'));
            if (! $request instanceof DetailRequest) {
                throw new \LogicException('Invalid persisted request.');
            }

            return $request;
        }
        $reference = $this->listing->detailReference($intent);
        if ($reference === null || $reference->procurementId !== $procurement) {
            throw new \LogicException('Missing immutable detail request.');
        }
        $head = LedgerRow::one('SELECT p.source_identity_type,p.source_identity_value,r.code AS registry,f.code AS platform
            FROM prc_procurements p JOIN prc_registries r ON r.id=p.registry_id JOIN prc_platforms f ON f.id=p.platform_id WHERE p.id=?', [$procurement]) ?? throw new \LogicException('Missing subject.');

        return new DetailRequest($head->string('registry'), new SourceIdentity(new PlatformCode($head->string('platform')),
            new SourceIdentifierType($head->string('source_identity_type')), new SourceIdentifierValue($head->string('source_identity_value'))), $reference->locator);
    }

    public function saveRequest(int $procurement, int $head, ProcessUuid $intent, DetailRequest $request): void
    {
        $this->insert($procurement, $head, $intent->value.':request', 'not_applicable', $this->codec->encode($request), null);
    }

    public function progress(DetailInput $input, DetailProgress $progress): void
    {
        CallerTransaction::require('Detail part checkpoint');
        $this->saveRequest($input->procurementId, $input->expectedHeadGeneration, $input->intentId, $this->request($input->procurementId, $input->intentId));
        foreach ($progress->parts as $part => $ref) {
            $this->insert($input->procurementId, $input->expectedHeadGeneration, $input->intentId->value.':'.$progress->pass.':'.$part,
                'complete', $this->codec->encode($ref), null);
        }
        if ($progress->consistency === SnapshotConsistency::Unstable) {
            $this->insert($input->procurementId, $input->expectedHeadGeneration, $input->intentId->value.':unstable_source', 'partial', $this->codec->encode($progress), null);
        }
    }

    public function insert(int $procurement, int $head, string $part, string $completeness, string $payload, ?string $revision): void
    {
        CallerTransaction::require('Detail immutable observation');
        $id = ListingStoreRepository::id('snapshot:'.$procurement.':'.$head.':'.$part);
        DB::insert('INSERT INTO prc_detail_snapshots(id,procurement_id,input_revision,part_key,completeness,result_ref,result_ref_schema_version,source_version,created_at)
            VALUES (?,?,?,?,?,?::jsonb,1,?,clock_timestamp()) ON CONFLICT(procurement_id,input_revision,part_key) DO NOTHING',
            [$id->value, $procurement, $head, $part, $completeness, $payload, $revision]);
        $row = LedgerRow::one('SELECT result_ref::text AS payload FROM prc_detail_snapshots WHERE id=?', [$id->value]);
        if ($row === null || json_decode($row->string('payload'), true, flags: JSON_THROW_ON_ERROR) != json_decode($payload, true, flags: JSON_THROW_ON_ERROR)) {
            throw new \LogicException('Immutable detail checkpoint conflict.');
        }
    }
}
