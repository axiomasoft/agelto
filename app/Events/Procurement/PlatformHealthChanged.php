<?php

declare(strict_types=1);

namespace App\Events\Procurement;

use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Первое доменное событие проекта: каталог `app/Events`, нагрузка только значениями и
 * автодискавери слушателей из `app/Listeners` — конвенция для следующих доменных событий.
 *
 * Модели намеренно не передаются: будущий queued-слушатель не должен неявно менять семантику
 * события через сериализацию Eloquent.
 */
final readonly class PlatformHealthChanged
{
    use Dispatchable;

    public function __construct(
        public int $platformId,
        public PlatformCheckKind $checkKind,
        public ?PlatformCheckVerdict $previousVerdict,
        public PlatformCheckVerdict $verdict,
        public int $checkId,
        public string $message,
    ) {}
}
