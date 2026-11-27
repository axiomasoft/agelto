<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Fixtures\RelationManagers;

use App\Filament\Concerns\AuthorizesReadOnlyRelation;
use App\Filament\Pages\FinalPromptPage;
use App\Filament\Resources\Procurement\Ai\Analyses\AnalysisResource;
use App\Filament\Resources\Procurement\Ai\Fixtures\Related\FixtureRunResource;
use App\Filament\Resources\Procurement\Ai\Fixtures\Schemas\RunResultSchema;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Ai\Fixture;
use App\Repositories\Procurement\Ai\AnalysisRunReadRepository;
use App\Services\Llm\ReportMoney;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Canonical run history: immutable recipe identity, derived work state and original ledger spend. */
class RunsRelationManager extends RelationManager
{
    use AuthorizesReadOnlyRelation;

    protected static ?string $azguardResource = FixtureRunResource::class;

    protected static string $relationship = 'runs';

    protected static ?string $title = 'Прогоны';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(function (Builder $query): Builder {
                /** @var Builder<AnalysisRun> $query */
                return app(AnalysisRunReadRepository::class)->decorate($query)->with('analyses');
            })
            ->columns([
                TextColumn::make('execution_state')
                    ->label('Статус')
                    ->badge(),
                TextColumn::make('requested_reason')
                    ->label('Основание')
                    ->badge(),
                TextColumn::make('started_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->label('Завершён')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('duration')
                    ->label('Время с создания')
                    ->state(fn (AnalysisRun $record): ?string => $record->started_at === null
                        ? null
                        : $record->started_at->diffForHumans($record->finished_at ?? now(), syntax: CarbonInterface::DIFF_ABSOLUTE))
                    ->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('prompt_tokens')
                    ->label('Prompt')
                    ->numeric()
                    ->placeholder('нет данных')
                    ->visibleFrom('md'),
                TextColumn::make('cached_tokens')
                    ->label('Cached')
                    ->numeric()
                    ->placeholder('нет данных')
                    ->visibleFrom('md'),
                TextColumn::make('completion_tokens')
                    ->label('Completion')
                    ->numeric()
                    ->placeholder('нет данных')
                    ->visibleFrom('md'),
                TextColumn::make('total_tokens')
                    ->label('Всего токенов')
                    ->numeric()
                    ->placeholder('нет данных')
                    ->visibleFrom('md'),
                TextColumn::make('unknown_calls')
                    ->label('Неуточнённые usage/charge')
                    ->numeric()->visibleFrom('md'),
                TextColumn::make('reserved_micros')
                    ->label('Остаётся в резерве')
                    ->formatStateUsing(fn (?int $state, AnalysisRun $record): ?string => $state === null || $record->cost_currency === null
                        ? null : ReportMoney::format($state, $record->cost_currency))
                    ->placeholder('нет данных')->visibleFrom('md'),
                TextColumn::make('cost_micros')
                    ->label('Цена')
                    ->formatStateUsing(fn (?int $state, AnalysisRun $record): ?string => $state === null || $record->cost_currency === null
                        ? null : ReportMoney::format($state, $record->cost_currency))
                    ->placeholder('нет данных')
                    ->visibleFrom('md'),
            ])
            ->defaultSort('started_at', 'desc')
            ->poll(fn (): ?string => $this->ownerFixture()->document_id !== null && app(AnalysisRunReadRepository::class)->hasActiveForDocument($this->ownerFixture()->document_id) ? '5s' : null)
            ->recordAction('result')
            ->recordActions([
                $this->resultAction(),
                Action::make('viewAnalysis')
                    ->label('Анализ')
                    ->authorize(fn (): bool => AnalysisResource::canViewAny())
                    ->visible(fn (AnalysisRun $record): bool => $record->analyses->isNotEmpty()
                        && $this->ownerFixture()->document?->platform?->is_public === true)
                    ->url(fn (AnalysisRun $record): string => AnalysisResource::getUrl('view', [
                        'record' => $record->analyses->sortByDesc('id')->first(),
                    ]))
                    ->openUrlInNewTab(),
                Action::make('finalPrompt')
                    ->label('Финальный промт')
                    ->authorize(fn (): bool => FinalPromptPage::canAccess())
                    ->url(fn (AnalysisRun $record): string => FinalPromptPage::getUrl(['run' => $record->id]))
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([
                //
            ]);
    }

    /**
     * Модалка результата прогона: содержимое собирает `RunResultSchema` — вердикт
     * первичного анализа, сводка ОТ АНАЛИЗАТОРА типа и эталон рядом. Клик по строке открывает её
     * же (`recordAction('result')`). Форма — показ: submit-действия у модалки нет (эталон из
     * админки не правится).
     */
    private function resultAction(): Action
    {
        return Action::make('result')
            ->label('Результат')
            ->authorize(fn (AnalysisRun $record): bool => $this->getAuthorizationResponse(action: 'view', record: $record)->allowed())
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
     * `getOwnerRecord` типизирован базовым `Model` (владелец relation manager'а — любая модель
     * Filament-ресурса); здесь владелец всегда `Fixture` — сужение нужно ТОЛЬКО ради `runs`.
     */
    private function ownerFixture(): Fixture
    {
        $owner = $this->getOwnerRecord();

        assert($owner instanceof Fixture);

        return $owner;
    }
}
