<?php

declare(strict_types=1);

namespace App\Services\Procurement\Detail;

use App\Dto\Procurement\Details\CommonInfoData;
use App\Dto\Procurement\Details\CommonInfoSnapshot;
use App\Dto\Procurement\Details\FinancingRowData;
use App\Dto\Procurement\Details\PurchaseItemRowData;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Enums\Telemetry\LogChannel;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\ProcurementDetailStoreRepository;
use App\Services\Procurement\Acquisition\Detail\ExtensionWriter;
use App\Services\Reference\ClassifierResolver;
use Illuminate\Support\Facades\DB;

/**
 * DTO → БД для вкладки `common-info`: registered adapter extension, `prc_financing`
 * (replace-set по годам, без строки «Всего»), `prc_items` (upsert по `position_number`,
 * с резолвом `okpd2_ref_id`/`ktru_ref_id`). Никакого DOM-парсинга здесь — вход уже
 * разобранный {@see CommonInfoData} от platform parser.
 * Роли/организации/`customer_org_id` синхронизируются независимо, только для подтверждённого
 * полного parties snapshot.
 */
final readonly class CommonInfoProcessor
{
    public function __construct(
        private ExtensionWriter $extensions,
        private ClassifierResolver $classifierResolver,
        private ProcurementDetailStoreRepository $storeRepository,
        private OrganizationPartiesProcessor $organizationPartiesProcessor,
    ) {}

    public function process(Procurement $procurement, CommonInfoSnapshot $snapshot): void
    {
        $reconcileCommonInfo = $snapshot->authorizesCommonInfoReconciliation();
        $reconcileParties = $snapshot->authorizesPartiesReconciliation();

        if (! $reconcileCommonInfo && ! $reconcileParties) {
            LogChannel::Procurement->warning(message: 'CommonInfoProcessor: snapshot не содержит подтверждённых полных проекций.', context: [
                'procurement_id' => $procurement->id,
                'common_info_status' => $snapshot->status->value,
                'parties_complete' => $snapshot->partiesComplete,
            ]);

            return;
        }

        $platformCode = DB::table('prc_platforms')->where('id', $procurement->platform_id)->value('code');
        if (! is_string($platformCode)) {
            throw new \LogicException('Missing procurement platform.');
        }
        $platform = new PlatformCode($platformCode);
        DB::transaction(function () use ($procurement, $reconcileCommonInfo, $reconcileParties, $snapshot, $platform): void {
            if ($reconcileCommonInfo) {
                $data = $snapshot->commonInfo;

                $this->extensions->persist($platform, $procurement->id, $data->extension);

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
     * @return list<array<string, mixed>>
     */
    private function financingRows(CommonInfoData $data): array
    {
        return array_map(
            fn (FinancingRowData $row): array => [
                'fiscal_year' => $row->fiscalYear,
                'amount' => $row->amount,
                'kind' => $row->kind,
                'kbk' => $row->kbk,
                'kbk_ref_id' => $this->classifierResolver->resolveKbk(code: $row->kbk),
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
