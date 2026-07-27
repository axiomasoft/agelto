<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Gsfx;

use App\Services\Procurement\Prepare\GsfxStrategy;
use RuntimeException;

/**
 * Контейнер/`Data.xml` не читается (битый ZIP, отсутствует `Data.xml`, нечитаемый XML, попытка
 * XXE) — ожидаемый исход недоверенного входа из закупки, {@see GsfxStrategy} переводит его в
 * `unprocessable(unreadable_format)`, а не пробрасывает как программную ошибку.
 */
final class GsfxUnreadableException extends RuntimeException {}
