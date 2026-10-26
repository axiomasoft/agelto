<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Contracts;

/**
 * Одна bounded категория ReconcileDaemon (bp-sql-recover): expired running, expired admitted,
 * blocked scans, invariant scan, handler fault sweep. Категория сама берёт полный lock prefix и
 * повторяет locked predicates; daemon лишь вызывает её на каждом tick и изолирует её сбой от
 * соседних категорий. Реальные категории регистрирует P1.11, не P1.10.
 */
interface ReconcileCategory
{
    /** Стабильное bounded имя для кода ошибки и telemetry. */
    public function name(): string;

    /** @return int число обработанных единиц (не больше bounded batch категории) */
    public function reconcile(): int;
}
