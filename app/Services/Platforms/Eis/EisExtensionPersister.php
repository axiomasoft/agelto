<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis;

use App\Dto\Platforms\Eis\EisDetailExtension;
use App\Models\Platforms\Eis\EisDetails;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Services\Procurement\Platform\Contracts\ExtensionPersister;

/** @implements ExtensionPersister<EisDetailExtension> */
final readonly class EisExtensionPersister implements ExtensionPersister
{
    public function persist(int $procurementId, object $extension): void
    {
        CallerTransaction::require('EIS extension publication');
        $extension = $this->typed($extension);
        $attributes = array_filter([
            'ikz' => $extension->ikz, 'etp_number' => $extension->etpNumber, 'registry_guid' => $extension->registryGuid,
        ], static fn (?string $value): bool => $value !== null);
        if ($extension->hasCommonInfo) {
            $contact = array_filter(['name' => $extension->contactPersonName, 'email' => $extension->contactPersonEmail],
                static fn (?string $value): bool => $value !== null);
            $attributes += [
                'ikz_year' => $extension->ikzYear, 'ikz_customer_code' => $extension->ikzCustomerCode,
                'ikz_plan_number' => $extension->ikzPlanNumber, 'ikz_plangraph_number' => $extension->ikzPlangraphNumber,
                'ikz_okpd_class' => $extension->ikzOkpdClass, 'ikz_okpd_subclass' => $extension->ikzOkpdSubclass,
                'ikz_okpd_group' => $extension->ikzOkpdGroup, 'ikz_kvr' => $extension->ikzKvr,
                'etp_name' => $extension->etpName, 'etp_url' => $extension->etpUrl,
                'contact_person' => $contact === [] ? null : json_encode($contact, JSON_THROW_ON_ERROR),
            ];
        }
        if ($attributes === []) {
            return;
        }
        EisDetails::query()->upsert(
            [['procurement_id' => $procurementId, ...$attributes]],
            ['procurement_id'], array_keys($attributes),
        );
    }

    private function typed(object $extension): EisDetailExtension
    {
        if (! $extension instanceof EisDetailExtension) {
            throw new \InvalidArgumentException('Expected EIS detail extension.');
        }

        return $extension;
    }
}
