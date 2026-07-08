<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Jobs\Procurement\PrepareDocumentJob;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;

/**
 * Стратегия подготовки документа к скринингу/извлечению (ТЗ 03 §3.3, канон v1
 * `Old/app/Process/DocumentPrepare/Strategies/PreparationStrategy.php`) — выбор по
 * расширению/сигнатуре версии, реестр порядка — `config('ai.prepare.strategies')`.
 * Стратегия сама решает терминальный исход (успех/`unprocessable`); не переводит документ
 * в `prepared` — это делает {@see PrepareDocumentJob} после успеха.
 */
interface PreparationStrategy
{
    public function canProcess(DocumentVersion $version): bool;

    public function process(Document $document, DocumentVersion $version): void;
}
