<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Archive;

use RuntimeException;

/**
 * Архив зашифрован (заголовки или содержимое) — `unprocessable(password_protected)` (P3.6).
 */
final class ArchivePasswordProtectedException extends RuntimeException {}
