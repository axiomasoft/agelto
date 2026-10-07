<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Состояние этапа, производное от полей `prc_source_circuits` (`SourceCircuit::status()`).
 * Отдельной колонки нет: каждое состояние держится своим полем, приоритет — порядок ниже.
 */
enum SourceStageStatus: string implements HasColor, HasLabel
{
    /** `manually_paused` — оператор поставил паузу. */
    case Paused = 'paused';

    /** `held_at` — серия отказов «изменилась структура»; ждёт оператора. */
    case Held = 'held';

    /** `retry_at` в будущем — ждёт по таймеру, затем сам продолжит. */
    case Waiting = 'waiting';

    case Running = 'running';

    public function admits(): bool
    {
        return $this === self::Running;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Paused => 'Пауза оператора',
            self::Held => 'Остановлен: изменилась структура',
            self::Waiting => 'Ожидание',
            self::Running => 'Работает',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Paused, self::Held => 'danger',
            self::Waiting => 'warning',
            self::Running => 'success',
        };
    }
}
