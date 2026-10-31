<?php

declare(strict_types=1);

namespace App\Dto\Platforms\Eis;

/** Config/registry boundary: all four keys required; null explicitly declares optional absence. */
final readonly class EisCardRoutes
{
    /**
     * @param  array<string,array<string,?string>>  $procedures
     * @param  array<string,string>  $procedureTypes
     */
    public function __construct(public array $procedures, public array $procedureTypes = [])
    {
        foreach ($procedures as $procedure => $tabs) {
            if (! preg_match('/^[a-z][a-z0-9]{1,15}$/D', $procedure)
                || count($tabs) !== 4 || array_diff(['common-info', 'documents', 'event-journal', 'supplier-results'], array_keys($tabs)) !== []
                || $tabs['common-info'] === null || $tabs['documents'] === null) {
                throw new \InvalidArgumentException('Incomplete EIS card routes.');
            }
            foreach ($tabs as $url) {
                if ($url !== null && (substr_count($url, '{regNumber}') !== 1 || ! str_starts_with($url, 'https://'))) {
                    throw new \InvalidArgumentException('Invalid EIS card route template.');
                }
            }
        }
        foreach ($procedureTypes as $label => $procedure) {
            if ($label === '' || ! isset($procedures[$procedure])) {
                throw new \InvalidArgumentException('Invalid EIS procedure label mapping.');
            }
        }
    }
}
