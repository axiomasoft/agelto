<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

/** Known Eloquent/query-builder writers; this is not a general PHP/SQL interpreter. */
final class AcquisitionWriteRules
{
    /** @return list<string> */
    public static function violations(string $path, string $source): array
    {
        $violations = [];
        $writes = '(?:create|insert|insertGetId|upsert|update|updateOrCreate|firstOrCreate|save|delete)';
        if ($path !== 'app/Services/Platforms/Eis/EisExtensionPersister.php'
            && preg_match('/(?:\bEisDetails(?=::)|(?:table|from)\([\'"]prc_eis_details[\'"]\))[^;]*?(?:->|::)'.$writes.'\s*\(/s', $source)) {
            $violations[] = $path.': extension write outside EisExtensionPersister';
        }
        if ($path !== 'app/Repositories/Procurement/ProcurementStoreRepository.php'
            && preg_match('/(?:\bProcurement(?=::)|(?:table|from)\([\'"]prc_procurements[\'"]\))[^;]*?(?:->|::)(?:create|insert|insertGetId|upsert|firstOrCreate|updateOrCreate)\s*\(/s', $source)) {
            $violations[] = $path.': canonical creator outside ProcurementStoreRepository';
        }
        if (preg_match('/(?:\bProcurement(?=::)|(?:table|from)\([\'"]prc_procurements[\'"]\))[^;]*?->where\w*\([^;]*?attributes(?:->|#>|\s*::)/s', $source)) {
            $violations[] = $path.': query of raw procurement attributes';
        }

        return $violations;
    }
}
