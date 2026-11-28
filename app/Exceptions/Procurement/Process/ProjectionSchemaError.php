<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Process;

/**
 * Событие или измерение, которое каталог projection не умеет выразить (неизвестное состояние,
 * unbounded label, отсутствующий source fact). Изолирует одно событие: оно остаётся unapplied
 * с incident, остальные event IDs продолжают проецироваться. Сообщение — bounded код, не payload.
 */
final class ProjectionSchemaError extends \InvalidArgumentException {}
