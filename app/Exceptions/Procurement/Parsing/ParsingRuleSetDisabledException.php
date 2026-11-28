<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Parsing;

use RuntimeException;

/**
 * Набор правил парсинга отключён (`is_enabled = false`) и не может быть использован.
 */
final class ParsingRuleSetDisabledException extends RuntimeException {}
