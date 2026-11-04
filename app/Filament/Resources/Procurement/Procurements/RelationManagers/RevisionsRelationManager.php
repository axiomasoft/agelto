<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Procurements\RelationManagers;

use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementRevision;
use App\Services\Procurement\History\RevisionDiffService;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Read-only список редакций закупки. Сравнение делегировано доменному
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
            ->visible(fn (ProcurementRevision $record): bool => $record->revision_number > 1)
            ->modalHeading('Изменения редакции')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Закрыть')
            ->modalContent(function (ProcurementRevision $record): HtmlString {
                $previous = $this->ownerProcurement()->revisions()
                    ->whereBetween('detected_at', [
                        Carbon::parse($this->rawDateTime($this->ownerProcurement(), 'published_at')),
                        Carbon::parse($this->rawDateTime($record, 'detected_at')),
                    ])
                    ->where('revision_number', '<', $record->revision_number)
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
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'да' : 'нет',
            default => '—',
        };
    }

    /** @return array<string, mixed> */
    private function snapshot(ProcurementRevision $revision): array
    {
        return $revision->snapshot;
    }

    private function rawDateTime(Model $model, string $attribute): DateTimeInterface|float|int|string|null
    {
        $value = $model->getRawOriginal($attribute);

        return is_string($value) || is_int($value) || is_float($value) || $value instanceof DateTimeInterface
            ? $value
            : null;
    }
}
