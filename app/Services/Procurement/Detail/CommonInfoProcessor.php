<?php

declare(strict_types=1);

namespace App\Services\Procurement\Detail;

use App\Dto\Procurement\Details\CommonInfoData;
use App\Dto\Procurement\Details\CommonInfoSnapshot;
use App\Dto\Procurement\Details\FinancingRowData;
use App\Dto\Procurement\Details\PurchaseItemRowData;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\ProcurementDetailStoreRepository;
use App\Services\Procurement\Eis\Card\CommonInfoParser;
use App\Services\Reference\ClassifierResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DTO → БД для вкладки `common-info`: `prc_eis_details` (1:1, upsert), `prc_financing`
 * (replace-set по годам, без строки «Всего»), `prc_items` (upsert по `position_number`,
 * с резолвом `okpd2_ref_id`/`ktru_ref_id`). Никакого DOM-парсинга здесь — вход уже
 * разобранный {@see CommonInfoData} от {@see CommonInfoParser}.
 * Роли/организации/`customer_org_id` синхронизируются независимо, только для подтверждённого
 * полного parties snapshot.
 */
final readonly class CommonInfoProcessor
{
    public function __construct(
        private ClassifierResolver $classifierResolver,
        private ProcurementDetailStoreRepository $storeRepository,
        private OrganizationPartiesProcessor $organizationPartiesProcessor,
    ) {}

    public function process(Procurement $procurement, CommonInfoSnapshot $snapshot): void
    {
        $reconcileCommonInfo = $snapshot->authorizesCommonInfoReconciliation();
        $reconcileParties = $snapshot->authorizesPartiesReconciliation();

        if (! $reconcileCommonInfo && ! $reconcileParties) {
            Log::warning(message: 'CommonInfoProcessor: snapshot не содержит подтверждённых полных проекций.', context: [
                'procurement_id' => $procurement->id,
                'common_info_status' => $snapshot->status->value,
                'parties_complete' => $snapshot->partiesComplete,
            ]);

            return;
        }

        DB::transaction(function () use ($procurement, $reconcileCommonInfo, $reconcileParties, $snapshot): void {
            if ($reconcileCommonInfo) {
                $data = $snapshot->commonInfo;

                if (! $this->isEmpty($data)) {
                    $this->storeRepository->storeEisDetails(
                        procurementId: $procurement->id,
                        attributes: $this->eisDetailsAttributes($data),
                    );
                }

                $this->storeRepository->replaceFinancing(
                    procurementId: $procurement->id,
                    rows: $this->financingRows($data),
                );

                foreach ($data->items as $item) {
                    $this->storeRepository->upsertItem(
                        procurementId: $procurement->id,
                        attributes: $this->itemAttributes($item),
                    );
                }

                $this->storeRepository->deleteItemsMissingFromSnapshot(
                    procurementId: $procurement->id,
                    positionNumbers: array_map(static fn (PurchaseItemRowData $item): int => $item->positionNumber, $data->items),
                );
            }

            if ($reconcileParties) {
                $this->organizationPartiesProcessor->sync(procurement: $procurement, parties: $snapshot->parties);
            }
        });
    }

    /**
     * Ноль-как-успех запрещён (Д-8, `research/10` §2): пустая строка `prc_eis_details`
     * неотличима от «карточка снята, данных нет» — не пишем её вовсе, если разбор не дал
     * ни одного атрибута, ни строки финансирования, ни позиции.
     */
    private function isEmpty(CommonInfoData $data): bool
    {
        return $data->ikzYear === null
            && $data->ikzCustomerCode === null
            && $data->ikzPlanNumber === null
            && $data->ikzPlangraphNumber === null
            && $data->ikzOkpdClass === null
            && $data->ikzOkpdSubclass === null
            && $data->ikzOkpdGroup === null
            && $data->ikzKvr === null
            && $data->etpName === null
            && $data->etpUrl === null
            && $data->contactPersonName === null
            && $data->contactPersonEmail === null
            && $data->financingRows === []
            && $data->items === [];
    }

    /**
     * @return array<string, mixed>
     */
    private function eisDetailsAttributes(CommonInfoData $data): array
    {
        $contactPerson = array_filter([
            'name' => $data->contactPersonName,
            'email' => $data->contactPersonEmail,
        ], static fn (?string $value): bool => $value !== null);

        return [
            'ikz_year' => $data->ikzYear,
            'ikz_customer_code' => $data->ikzCustomerCode,
            'ikz_plan_number' => $data->ikzPlanNumber,
            'ikz_plangraph_number' => $data->ikzPlangraphNumber,
            'ikz_okpd_class' => $data->ikzOkpdClass,
            'ikz_okpd_subclass' => $data->ikzOkpdSubclass,
            'ikz_okpd_group' => $data->ikzOkpdGroup,
            'ikz_kvr' => $data->ikzKvr,
            'etp_name' => $data->etpName,
            'etp_url' => $data->etpUrl,
            'contact_person' => $contactPerson !== [] ? $contactPerson : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function financingRows(CommonInfoData $data): array
    {
        return array_map(
            static fn (FinancingRowData $row): array => [
                'fiscal_year' => $row->fiscalYear,
                'amount' => $row->amount,
                'kind' => $row->kind,
                'kbk' => $row->kbk,
                'kvr' => $row->kvr,
                'receipt_code' => $row->receiptCode,
                'is_beyond_horizon' => $row->isBeyondHorizon,
            ],
            $data->financingRows,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function itemAttributes(PurchaseItemRowData $item): array
    {
        $classifierCode = $item->classifierCodeOkpd2 ?? $item->classifierCodeKtru;

        return [
            'position_number' => $item->positionNumber,
            'name' => $item->name,
            'characteristics' => $item->characteristics,
            'classifier_scheme' => $item->classifierScheme,
            'classifier_code' => $classifierCode,
            'okpd2_ref_id' => $this->classifierResolver->resolveOkpd2(code: $item->classifierCodeOkpd2),
            'ktru_ref_id' => $this->classifierResolver->resolveKtru(code: $item->classifierCodeKtru),
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'unit_price' => $item->unitPrice,
            'total_cost' => $item->totalCost,
        ];
    }
}
