<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\SourceCircuits\Pages;

use App\Filament\Resources\Procurement\Crawler\SourceCircuits\SourceCircuitResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Правка порогов брейкера. Состояние (`state`, счётчики, `retry_at`) здесь не редактируется —
 * им управляют только переходы контроллера и действия «Пауза»/«Возобновить сейчас».
 */
final class EditSourceCircuit extends EditRecord
{
    protected static string $resource = SourceCircuitResource::class;
}
