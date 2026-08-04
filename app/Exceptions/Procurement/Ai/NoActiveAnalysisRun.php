<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Ai;

use RuntimeException;

/**
 * Нет активного (незавершённого) `AnalysisRun` для версии документа на момент резолва анализа
 * (`AnalysisStoreRepository::firstOrCreate()`/`createRerun()`, §3.5 root/document-contour.md) —
 * launch-точка обязана завести прогон ДО диспатча job'а/harness; nullable-возврат вместо
 * исключения превратил бы «прогон потерян» в тихий `null` (Implementation Rule 9, P1.6).
 */
final class NoActiveAnalysisRun extends RuntimeException
{
    public function __construct(int $documentVersionId)
    {
        parent::__construct("Нет активного прогона для версии документа #{$documentVersionId}.");
    }
}
