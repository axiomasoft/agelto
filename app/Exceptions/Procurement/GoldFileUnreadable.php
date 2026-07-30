<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement;

use App\Services\Procurement\Fixture\FixtureCaseResolver;

/**
 * Gold-файл эталона (`<CODE>.gold.json`) не найден или не парсится как JSON —
 * {@see FixtureCaseResolver}.
 */
final class GoldFileUnreadable extends OperationFailed {}
