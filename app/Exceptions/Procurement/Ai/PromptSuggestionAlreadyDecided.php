<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Ai;

use App\Exceptions\Procurement\OperationFailed;

/**
 * Предложение уже решено человеком — повторное решение отклоняется ДО мутации (P5.6,
 * Implementation Rule 4): переходы очереди идут только из `pending`, и второе решение поверх
 * `approved` затёрло бы аудит первого (кто/когда/почему), ради которого очередь и существует.
 */
final class PromptSuggestionAlreadyDecided extends OperationFailed
{
    public function __construct(string $currentStatus)
    {
        parent::__construct("Предложение уже решено (текущий статус: {$currentStatus}) — повторное решение отклонено.");
    }
}
