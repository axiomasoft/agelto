<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement;

use RuntimeException;

/**
 * Единая форма отказа «операция невозможна» сервисного слоя AI-контура (§1
 * `root/service-layer-map.md`, P3.1/P3.2/P3.3): ОДИН `catch (OperationFailed $e)` в `handle()`
 * команды — `$this->error($e->getMessage()); return self::FAILURE;`. `extends RuntimeException`,
 * чтобы существующий `catch (RuntimeException)` (`PromptsApplyCommand:64`) не перестал ловить.
 */
abstract class OperationFailed extends RuntimeException {}
