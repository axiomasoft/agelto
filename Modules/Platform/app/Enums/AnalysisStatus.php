<?php

declare(strict_types=1);

namespace Platform\Enums;

use Filament\Support\Contracts\HasLabel;

enum AnalysisStatus: string implements HasLabel
{
    case NONE = 'none';           // Файл еще не проверялся
    case WAITING = 'waiting';     // В очереди на анализ
    case SUCCESS = 'success';     // Анализ завершен (Смета найдена и подтверждена)
    case SKIPPED = 'skipped';     // ИИ определил, что файл не является сметой
    case FAILED = 'failed';       // Ошибка при попытке анализа (API Error / Timeout)

    public function getLabel(): ?string
    {
        return match ($this) {
            self::NONE => 'Не проверялся',
            self::WAITING => 'В очереди на анализ',
            self::SUCCESS => 'Смета найдена',
            self::SKIPPED => 'Не смета',
            self::FAILED => 'Ошибка анализа',
        };
    }
}
