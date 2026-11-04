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
use App\Repositories\Procurement\ProcurementDetailStoreRepository;
use App\Repositories\Procurement\ProcurementStoreRepository;
use App\Services\Organization\OrganizationResolver;
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
    ) {}

    public function process(Procurement $procurement, SupplierResultsData $data): void
    {
        if ($data->protocol === null && $data->bids === [] && $data->award === null) {
            return;
        }

        DB::transaction(function () use ($procurement, $data): void {
            $protocol = $data->protocol !== null ? $this->storeProtocol($procurement, $data->protocol) : null;

            foreach ($data->bids as $bid) {
                $this->storeBid($procurement, $protocol, $bid);
            }

            if ($data->award !== null) {
                $this->storeAward($procurement, $data->award);
            }

            $this->outcomeEvaluator->evaluate(procurement: $procurement);
        });
    }

    private function storeProtocol(Procurement $procurement, ProtocolData $data): Protocol
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
                occurredAt: $data->publishedAt ?? $data->signedAt ?? Carbon::now(),
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

    private function storeAward(Procurement $procurement, AwardData $data): Award
    {
        $winner = $data->winner !== null
            ? $this->organizationResolver->resolve($data->winner)
            : null;

        $attributes = [
            'final_price' => $data->finalPrice,
            'status' => $data->status,
            'awarded_at' => $data->awardedAt ?? Carbon::now(),
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
                occurredAt: $data->awardedAt ?? Carbon::now(),
                entityRef: ['award_id' => $award->id],
                payload: ['winner_org_id' => $winner?->id, 'final_price' => $data->finalPrice],
            );
        }

        return $award;
    }
}
