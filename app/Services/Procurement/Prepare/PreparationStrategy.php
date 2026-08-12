<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Enums\Procurement\Document\PreparationResult;
use App\Jobs\Procurement\PrepareDocumentJob;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;

/**
 * Стратегия подготовки документа к скринингу/извлечению (ТЗ 03 §3.3, канон v1
 * `Old/app/Process/DocumentPrepare/Strategies/PreparationStrategy.php`) — выбор по
 * расширению/сигнатуре версии, реестр порядка — `config('ai.prepare.strategies')`.
 * Стратегия не переводит документ в `prepared` — это делает {@see PrepareDocumentJob} после успеха.
 *
 * `process()` возвращает `true`, если стратегия обработала версию (произвела представление либо
 * признала оригинал годным входом), и `false` — «эта стратегия не годится, цепочка идёт дальше»
 * (D28: лимит инструмента ≠ приговор документу, D26). Терминальный `unprocessable` стратегия
 * ставит САМА только когда исход терминален ПО СУЩЕСТВУ (zip-бомба, пароль, битый формат) —
 * тогда {@see DocumentPreparer} останавливает цепочку по статусу документа. Упавшая с
 * исключением стратегия НЕ маскирует сбой под `false`: исключение пробрасывается наружу,
 * иначе диагностика прода умрёт.
 */
interface PreparationStrategy
{
    public function process(Document $document, DocumentVersion $version): PreparationResult;
}
