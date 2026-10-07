<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

use Filament\Support\Contracts\HasLabel;

/**
 * Тип записи журнала инцидентов источника (`prc_source_incidents`).
 */
enum SourceIncidentType: string implements HasLabel
{
    /** Блок не прочитан, курсор переведён через него (skip-and-record). */
    case BlockSkipped = 'block_skipped';

    /** Блок прочитан частично (ошибки строк). */
    case BlockDegraded = 'block_degraded';

    /**
     * Preflight карточки: на вкладке нет критичных якорей эталона (`card_tabs` в эталоне
     * healthcheck) — вёрстка изменилась, вкладка не разбирается.
     */
    case CardLayoutDrift = 'card_layout_drift';

    case CircuitOpened = 'circuit_opened';
    case CircuitHalfOpened = 'circuit_half_opened';
    case CircuitClosed = 'circuit_closed';

    /** Прогон приостановлен, потому что брейкер источника открыт. */
    case RunSuspended = 'run_suspended';

    /** Прогон возобновлён автоматически или оператором. */
    case RunResumed = 'run_resumed';

    case ManualPause = 'manual_pause';
    case ManualResume = 'manual_resume';

    public function severity(): SourceIncidentSeverity
    {
        return match ($this) {
            self::CircuitOpened, self::ManualPause => SourceIncidentSeverity::Critical,
            self::BlockSkipped, self::RunSuspended, self::CardLayoutDrift => SourceIncidentSeverity::Warning,
            default => SourceIncidentSeverity::Info,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::BlockSkipped => 'Блок пропущен',
            self::BlockDegraded => 'Блок прочитан частично',
            self::CardLayoutDrift => 'Изменилась вёрстка карточки',
            self::CircuitOpened => 'Источник поставлен на паузу',
            self::CircuitHalfOpened => 'Пробный запуск после паузы',
            self::CircuitClosed => 'Источник снова работает',
            self::RunSuspended => 'Прогон приостановлен',
            self::RunResumed => 'Прогон возобновлён',
            self::ManualPause => 'Пауза оператором',
            self::ManualResume => 'Возобновление оператором',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
