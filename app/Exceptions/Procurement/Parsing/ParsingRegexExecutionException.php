<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Parsing;

use RuntimeException;

/**
 * Ошибка безопасного выполнения регулярного выражения (превышение лимитов PCRE или длины входа).
 */
final class ParsingRegexExecutionException extends RuntimeException {}
