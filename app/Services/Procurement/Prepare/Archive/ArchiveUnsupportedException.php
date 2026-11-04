<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Archive;

use RuntimeException;

/**
 * Формат/содержимое архива не взял ни один распаковщик (битый, неподдержанная версия) —
 * `unprocessable(archive_unsupported)`.
 */
final class ArchiveUnsupportedException extends RuntimeException {}
