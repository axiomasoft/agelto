<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement;

/**
 * `config('ai.screening.enabled')` выключен (дефолт `false`) — запуск отклоняется ДО любой
 * мутации: иначе кнопка честно доведёт документ до `prepared` и молча остановится, не заведя
 * ни прогона, ни анализа, ни ошибки (`PrepareDocumentJob:83` — гейт того же конфига).
 */
final class ScreeningDisabled extends OperationFailed
{
    public function __construct()
    {
        parent::__construct('AI-скрининг выключен (AI_SCREENING_ENABLED) — прогон недоступен.');
    }
}
