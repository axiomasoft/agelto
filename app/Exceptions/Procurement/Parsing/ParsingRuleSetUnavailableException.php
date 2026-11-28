<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Parsing;

use RuntimeException;

/**
 * Активная версия набора правил парсинга отсутствует или недоступна.
 */
final class ParsingRuleSetUnavailableException extends RuntimeException {}
