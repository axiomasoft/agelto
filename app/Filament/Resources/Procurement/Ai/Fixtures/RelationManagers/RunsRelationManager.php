<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Fixtures\RelationManagers;

use App\Filament\Resources\Procurement\Ai\Analyses\AnalysisResource;
use App\Filament\Resources\Procurement\Ai\Fixtures\Schemas\RunResultSchema;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Ai\Fixture;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * История прогонов эталона (P5.3, §3.2/§3.6 root/document-contour.md): статус, режим, длительность
 * и слепок расхода В СТРОКЕ прогона — токены/цена читаются из колонок `AnalysisRun`, без
 * агрегации и без обращения к партиционированной таблице вызовов (Implementation Rule 1/2,
 * D5/D37/D39/D92/D134).
 */
class RunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    protected static ?string $title = 'Прогоны';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('uuid')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('analyses'))
            ->columns([
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge(),
                TextColumn::make('mode')
                    ->label('Режим')
                    ->badge(),
                TextColumn::make('started_at')
                    ->label('Начат')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->label('Завершён')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('duration')
                    ->label('Длительность')
                    ->state(fn (AnalysisRun $record): ?string => $record->started_at === null
                        ? null
                        : $record->started_at->diffForHumans($record->finished_at ?? now(), syntax: CarbonInterface::DIFF_ABSOLUTE))
                    ->placeholder('—'),
                TextColumn::make('prompt_tokens')
                    ->label('Prompt')
                    ->numeric()
                    ->placeholder('нет данных'),
                TextColumn::make('cached_tokens')
                    ->label('Cached')
                    ->numeric()
                    ->placeholder('нет данных'),
                TextColumn::make('completion_tokens')
                    ->label('Completion')
                    ->numeric()
                    ->placeholder('нет данных'),
                TextColumn::make('total_tokens')
                    ->label('Всего токенов')
                    ->numeric()
                    ->placeholder('нет данных'),
                TextColumn::make('cost_micros')
                    ->label('Цена')
                    ->formatStateUsing(fn (int $state): string => sprintf('$%.4f', $state / 1_000_000))
                    ->placeholder('нет данных'),
            ])
            ->defaultSort('started_at', 'desc')
            ->poll(fn (): ?string => $this->ownerFixture()->runs()->whereNull('finished_at')->exists() ? '5s' : null)
            ->recordAction('result')
            ->recordActions([
                $this->resultAction(),
                Action::make('viewAnalysis')
                    ->label('Анализ')
                    ->visible(fn (AnalysisRun $record): bool => $record->analyses->isNotEmpty())
                    ->url(fn (AnalysisRun $record): string => AnalysisResource::getUrl('view', [
                        'record' => $record->analyses->sortByDesc('id')->first(),
                    ]))
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([
                //
            ]);
    }

    /**
     * Модалка результата прогона (P5.4, D27/D48): содержимое собирает `RunResultSchema` — вердикт
     * первичного анализа, сводка ОТ АНАЛИЗАТОРА типа и эталон рядом. Клик по строке открывает её
     * же (`recordAction('result')`). Форма — показ: submit-действия у модалки нет (эталон из
     * админки не правится, D48).
     */
    private function resultAction(): Action
    {
        return Action::make('result')
            ->label('Результат')
            ->modalHeading('Результат прогона')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Закрыть')
            ->schema(fn (Schema $schema, AnalysisRun $record): Schema => RunResultSchema::configure(
                $schema,
                $this->ownerFixture(),
                $record,
            ));
    }

    /**
     * `getOwnerRecord()` типизирован базовым `Model` (владелец relation manager'а — любая модель
     * Filament-ресурса); здесь владелец всегда `Fixture` — сужение нужно ТОЛЬКО ради `runs()`.
     */
    private function ownerFixture(): Fixture
    {
        $owner = $this->getOwnerRecord();

        assert($owner instanceof Fixture);

        return $owner;
    }
}
