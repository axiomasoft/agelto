<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction\Exceptions;

use RuntimeException;

/**
 * Ответ `extract_rows` обрезан провайдером (`FinishReason::MaxTokens`) — сигнал оркестратору
 * уменьшить окно диапазона вдвое и повторить ТОТ ЖЕ начальный `row_index`. Аналог поведения `ReaskPolicy` для MAX_TOKENS — бабблится немедленно, без
 * re-ask/эскалации маршрутом.
 */
final class MaxTokensExceededException extends RuntimeException {}
