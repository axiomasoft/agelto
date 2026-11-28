<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement;

use RuntimeException;

/**
 * Отказ «операция невозможна» в сервисном слое AI-контура. Console caller показывает
 * сообщение через `$this->error()` и завершает команду с `FAILURE`. Наследование RuntimeException
 * сохраняет совместимость с существующими обработчиками отказов.
 */
abstract class OperationFailed extends RuntimeException {}
