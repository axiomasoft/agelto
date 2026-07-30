<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement;

/**
 * Версия документа уже несёт незавершённый прогон (`AnalysisRunStoreRepository::findActive()`,
 * §3.2 root/document-contour.md, partial unique `prc_ai_analysis_runs_active_version_unique`) —
 * запуск отклоняется ДО любой мутации: второй `start()` на занятой версии упал бы внутри job'а.
 */
final class AnalysisRunAlreadyActive extends OperationFailed
{
    public function __construct()
    {
        parent::__construct('У этого эталона уже идёт прогон — дождитесь его завершения.');
    }
}
