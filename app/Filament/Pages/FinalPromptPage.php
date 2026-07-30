<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Llm\LlmCall;
use App\Repositories\Llm\LlmCallReadRepository;
use App\Services\Llm\Dto\StoredPayload;
use App\Services\Llm\LlmPayloadReader;
use AzGuard\Filament\Concerns\HasAzGuardPage;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * Финальный (объединённый) промт, реально ушедший в провайдера, по каждому вызову прогона —
 * первичного анализа и извлечения (P5.5, бриф §5). Показывается СОХРАНЁННЫЙ payload
 * (`llm_calls.request_ref`/`response_ref`), пересборка композитором ЗАПРЕЩЕНА (D46). Первая
 * кастомная страница панели (D45) — авторизуется ПРИНУДИТЕЛЬНО трейтом `HasAzGuardPage`: скрытие
 * пункта навигации доступом не является, URL остаётся достижимым без трейта.
 */
final class FinalPromptPage extends Page implements HasTable
{
    use HasAzGuardPage;
    use InteractsWithTable;

    protected static ?string $slug = 'final-prompt/{run}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.final-prompt-page';

    public int $run;

    #[Url]
    public ?int $call = null;

    public function mount(int $run): void
    {
        $this->run = $run;
    }

    public function getTitle(): string
    {
        return 'Финальный промт прогона';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(LlmCallReadRepository::class)->forRun($this->run))
            ->columns([
                TextColumn::make('purpose')
                    ->label('Назначение')
                    ->badge(),
                TextColumn::make('provider')
                    ->label('Провайдер'),
                TextColumn::make('model')
                    ->label('Модель'),
                TextColumn::make('called_at')
                    ->label('Время')
                    ->dateTime(),
                TextColumn::make('success')
                    ->label('Успех')
                    ->badge(),
            ])
            ->defaultSort('called_at')
            ->recordAction('select')
            ->recordActions([
                Action::make('select')
                    ->label('Показать')
                    ->action(function (LlmCall $record): void {
                        $this->call = $record->id;
                    }),
            ]);
    }

    public function selectedCall(): ?LlmCall
    {
        if ($this->call === null) {
            return null;
        }

        return app(LlmCallReadRepository::class)->findInRun($this->call, $this->run);
    }

    public function requestPayload(LlmCall $call): StoredPayload
    {
        return app(LlmPayloadReader::class)->read($call->request_ref);
    }

    public function responsePayload(LlmCall $call): StoredPayload
    {
        return app(LlmPayloadReader::class)->read($call->response_ref);
    }
}
