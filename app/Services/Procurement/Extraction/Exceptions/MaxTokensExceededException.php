<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction\Exceptions;

use RuntimeException;

/**
 * Ответ `extract_rows` обрезан провайдером (`FinishReason::MaxTokens`) — сигнал оркестратору
 * уменьшить окно диапазона вдвое и повторить ТОТ ЖЕ начальный `row_index` (Implementation
 * Rules P6.1, аналог поведения `ReaskPolicy` для MAX_TOKENS — бабблится немедленно, без
 * re-ask/эскалации маршрутом, `docs/specs/v2/08-estimate-extraction.md` §6, research/E §6).
 */
final class MaxTokensExceededException extends RuntimeException {}
