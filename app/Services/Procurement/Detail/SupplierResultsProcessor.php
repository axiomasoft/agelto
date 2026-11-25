<?php

declare(strict_types=1);

namespace App\Services\Procurement\Detail;

use App\Dto\Procurement\Details\AwardData;
use App\Dto\Procurement\Details\BidData;
use App\Dto\Procurement\Details\ProtocolData;
use App\Dto\Procurement\Details\SupplierResultsData;
use App\Enums\Organization\ProcurementPartyRole;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Award;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\Protocol;
use App\Repositories\Organization\ProcurementPartyStoreRepository;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\ProcurementDetailStoreRepository;
use App\Repositories\Procurement\ProcurementStoreRepository;
use App\Services\Organization\OrganizationResolver;
use App\Services\PartitionCoverage;
use App\Services\Procurement\ProcurementOutcomeEvaluator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Записывает supplier-results через ProcurementDetailStoreRepository и ProcurementPartyStoreRepository:
 * protocols по source_number, bids построчно, awards с ролью winner для известной организации.
 * Анонимный award сохраняет ранг; повторный разбор не стирает позднее обогащение winner_org_id.
 * Пустая вкладка даёт no-op. Document_version_id протокола, clarifications и cancellations
 * не заполняются: вкладка не содержит необходимых ссылок и фактов.
 */
final readonly class SupplierResultsProcessor
{
    public function __construct(
        private OrganizationResolver $organizationResolver,
        private ProcurementDetailStoreRepository $detailStoreRepository,
        private ProcurementPartyStoreRepository $partyStoreRepository,
        private ProcurementStoreRepository $procurementStoreRepository,
        private ProcurementOutcomeEvaluator $outcomeEvaluator,
        private PartitionCoverage $partitions,
    ) {}

    public function process(Procurement $procurement, SupplierResultsData $data): void
    {
        $observedAt = Carbon::now();
        $this->ensurePartitions(data: $data, observedAt: $observedAt);
        DB::transaction(fn () => $this->storePrepared(procurement: $procurement, data: $data, observedAt: $observedAt));
    }

    public function ensurePartitions(SupplierResultsData $data, Carbon $observedAt): void
    {
        $dates = [];
        if ($data->protocol !== null) {
            $dates[] = $this->protocolEventAt(data: $data->protocol, observedAt: $observedAt);
        }
        if ($data->award !== null) {
            $dates[] = $data->award->awardedAt ?? $observedAt;
        }
        $this->partitions->ensureMonths(table: 'prc_events', dates: $dates);
    }

    public function storePrepared(Procurement $procurement, SupplierResultsData $data, Carbon $observedAt): void
    {
        if ($data->protocol === null && $data->bids === [] && $data->award === null) {
            return;
        }
        CallerTransaction::require('Supplier results publication');

        $protocol = $data->protocol === null ? null : $this->storeProtocol(
            procurement: $procurement, data: $data->protocol, occurredAt: $this->protocolEventAt(data: $data->protocol, observedAt: $observedAt));

        foreach ($data->bids as $bid) {
            $this->storeBid(procurement: $procurement, protocol: $protocol, data: $bid);
        }

        if ($data->award !== null) {
            $this->storeAward(procurement: $procurement, data: $data->award,
                occurredAt: ($data->award->awardedAt ?? $observedAt)->copy()->utc());
        }

        $this->outcomeEvaluator->evaluate(procurement: $procurement);
    }

    private function protocolEventAt(ProtocolData $data, Carbon $observedAt): Carbon
    {
        return ($data->publishedAt ?? $data->signedAt ?? $observedAt)->copy()->utc();
    }

    private function storeProtocol(Procurement $procurement, ProtocolData $data, Carbon $occurredAt): Protocol
    {
        $protocol = $this->detailStoreRepository->upsertProtocol(
            procurementId: $procurement->id,
            sourceNumber: $data->sourceNumber,
            attributes: [
                'type' => $data->type,
                'signed_at' => $data->signedAt,
                'published_at' => $data->publishedAt,
            ],
        );

        if ($protocol->wasRecentlyCreated) {
            $this->procurementStoreRepository->storeEvent(
                procurementId: $procurement->id,
                eventType: ProcurementEventType::ProtocolPublished,
                occurredAt: $occurredAt,
                entityRef: ['protocol_id' => $protocol->id],
                payload: ['source_number' => $data->sourceNumber, 'type' => $data->type],
            );
        }

        return $protocol;
    }

    private function storeBid(Procurement $procurement, ?Protocol $protocol, BidData $data): void
    {
        // Участник без надёжного идентификатора (ИНН/ОГРН) не резолвится через
        // OrganizationResolver — см. SupplierResultsParser/BidData: без ИНН/ОГРН резолвер
        // не отличает одно юрлицо от другого, склеивая разных участников в одну организацию.
        $organizationId = $data->organization !== null
            ? $this->organizationResolver->resolve($data->organization)->id
            : null;

        $this->detailStoreRepository->upsertBid(
            procurementId: $procurement->id,
            protocolId: $protocol?->id,
            organizationId: $organizationId,
            participantOrdinal: $data->participantOrdinal,
            attributes: [
                'bid_number' => $data->bidNumber,
                'participant_ordinal' => $data->participantOrdinal,
                'price' => $data->price,
                'status' => $data->status,
            ],
        );
    }

    private function storeAward(Procurement $procurement, AwardData $data, Carbon $occurredAt): Award
    {
        $winner = $data->winner !== null
            ? $this->organizationResolver->resolve($data->winner)
            : null;

        $attributes = [
            'final_price' => $data->finalPrice,
            'status' => $data->status,
            'awarded_at' => $occurredAt,
        ];

        if ($winner !== null) {
            $attributes['winner_org_id'] = $winner->id;
        }

        $award = $this->detailStoreRepository->upsertAward(
            procurementId: $procurement->id,
            lotId: null,
            attributes: $attributes,
        );

        if ($winner !== null) {
            $this->partyStoreRepository->upsertParty(
                procurementId: $procurement->id,
                organizationId: $winner->id,
                role: ProcurementPartyRole::Winner,
                attributes: null,
            );

            $this->partyStoreRepository->syncWinnerRoles(
                procurementId: $procurement->id,
                winnerOrgIds: [$winner->id],
            );
        }

        if ($award->wasRecentlyCreated) {
            $this->procurementStoreRepository->storeEvent(
                procurementId: $procurement->id,
                eventType: ProcurementEventType::AwardMade,
                occurredAt: $occurredAt,
                entityRef: ['award_id' => $award->id],
                payload: ['winner_org_id' => $winner?->id, 'final_price' => $data->finalPrice],
            );
        }

        return $award;
    }
}
