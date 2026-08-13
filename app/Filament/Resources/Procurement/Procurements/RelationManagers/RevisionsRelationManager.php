<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Procurements\RelationManagers;

use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementRevision;
use App\Services\Procurement\History\RevisionDiffService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Read-only список редакций закупки (P6.4). Сравнение делегировано доменному
 * `RevisionDiffService`: витрина только показывает уже определённый diff и не имеет формы
 * либо действий изменения, так как редакции — слепки конвейера.
 */
class RevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('revision_number')
            ->columns([
                TextColumn::make('revision_number')
                    ->label('Редакция')
                    ->sortable(),
                TextColumn::make('detected_at')
                    ->label('Обнаружена')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('source_updated_at')
                    ->label('Изменена на площадке')
                    ->dateTime()
                    ->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('change_reason')
                    ->label('Причина')
                    ->placeholder('—')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (?string $state): ?string => $state),
            ])
            ->filters([
                self::historyPeriodFilter(),
            ])
            ->recordActions([
                $this->compareAction(),
            ])
            ->defaultSort('revision_number', 'desc');
    }

    private function compareAction(): Action
    {
        return Action::make('comparePrevious')
            ->label('Сравнить с предыдущей')
            ->visible(fn (ProcurementRevision $record): bool => (int) $record->getRawOriginal('revision_number') > 1)
            ->modalHeading('Изменения редакции')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Закрыть')
            ->modalContent(function (ProcurementRevision $record): HtmlString {
                $previous = $this->ownerProcurement()->revisions()
                    ->whereBetween('detected_at', [
                        Carbon::parse($this->ownerProcurement()->getRawOriginal('published_at')),
                        Carbon::parse($record->getRawOriginal('detected_at')),
                    ])
                    ->where('revision_number', '<', (int) $record->getRawOriginal('revision_number'))
                    ->orderByDesc('revision_number')
                    ->first();

                if ($previous === null) {
                    return new HtmlString('<p>Предыдущая редакция не найдена.</p>');
                }

                $changes = app(RevisionDiffService::class)->diff(
                    oldSnapshot: $this->snapshot($previous),
                    newSnapshot: $this->snapshot($record),
                )->changes;

                if ($changes === []) {
                    return new HtmlString('<p>Изменений нет.</p>');
                }

                $items = collect($changes)->map(fn (array $change): string => sprintf(
                    '<li><strong>%s</strong>: %s → %s</li>',
                    e($change['label']),
                    e($this->displayValue($change['old'])),
                    e($this->displayValue($change['new'])),
                ))->implode('');

                return new HtmlString("<ul>{$items}</ul>");
            });
    }

    private static function historyPeriodFilter(): TernaryFilter
    {
        return TernaryFilter::make('all_time')
            ->label('Период')
            ->trueLabel('За всё время')
            ->falseLabel('Последние 90 дней')
            ->default(false)
            ->queries(
                true: static fn (Builder $query): Builder => $query,
                false: static fn (Builder $query): Builder => $query->where('detected_at', '>=', now()->subDays(90)),
            );
    }

    private function ownerProcurement(): Procurement
    {
        $owner = $this->getOwnerRecord();

        assert($owner instanceof Procurement);

        return $owner;
    }

    private function displayValue(mixed $value): string
    {
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $value === null ? '—' : (string) $value;
    }

    /** @return array<string, mixed> */
    private function snapshot(ProcurementRevision $revision): array
    {
        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode((string) $revision->getRawOriginal('snapshot'), true, flags: JSON_THROW_ON_ERROR);

        return $snapshot;
    }
}
