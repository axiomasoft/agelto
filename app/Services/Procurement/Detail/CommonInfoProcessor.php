<?php

declare(strict_types=1);

namespace App\Services\Procurement\Detail;

use App\Dto\Crawler\Details\CommonInfoData;
use App\Dto\Crawler\Details\PurchaseItemRowData;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\ProcurementDetailStoreRepository;
use App\Services\Crawler\Eis\Card\CommonInfoParser;
use App\Services\Reference\ClassifierResolver;

/**
 * DTO → БД для вкладки `common-info`: `prc_eis_details` (1:1, upsert), `prc_financing`
 * (replace-set по годам, без строки «Всего»), `prc_items` (upsert по `position_number`,
 * с резолвом `okpd2_ref_id`/`ktru_ref_id`). Никакого DOM-парсинга здесь — вход уже
 * разобранный {@see CommonInfoData} от {@see CommonInfoParser}.
 * Роли/организации/`customer_org_id` — вне этого процессора (P3.4).
 */
final readonly class CommonInfoProcessor
{
    public function __construct(
        private ClassifierResolver $classifierResolver,
        private ProcurementDetailStoreRepository $storeRepository,
    ) {}

    public function process(Procurement $procurement, CommonInfoData $data): void
    {
        $this->storeRepository->storeEisDetails(
            procurementId: $procurement->id,
            attributes: $this->eisDetailsAttributes($data),
        );

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
            static fn ($row): array => [
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
