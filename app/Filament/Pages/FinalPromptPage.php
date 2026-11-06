<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Llm\LlmCall;
use App\Repositories\Procurement\Ai\AnalysisCallReadRepository;
use App\Services\Llm\Dto\StoredPayload;
use App\Services\Llm\LlmPayloadReader;
use AzGuard\Filament\Authorization\FilamentGate;
use AzGuard\Filament\Concerns\AuthorizesPage;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/** Authorized canonical-call history and verified response body; no reconstructed request is claimed as sent. */
final class FinalPromptPage extends Page implements HasTable
{
    use AuthorizesPage;
    use InteractsWithTable;

    protected static ?string $azguardKey = 'final-prompt';

    protected static ?string $slug = 'final-prompt/{run}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.final-prompt-page';

    public static function canAccess(): bool
    {
        return FilamentGate::page(self::class, static fn (): Response => Response::deny())->allowed();
    }

    public string $run;

    #[Url]
    public ?string $call = null;

    public function mount(string $run): void
    {
        abort_unless(Str::isUuid($run), 404);
        $this->run = $run;
    }

    public function getTitle(): string
    {
        return 'Финальный промт прогона';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(AnalysisCallReadRepository::class)->forRun($this->run))
            ->columns([
                TextColumn::make('purpose')
                    ->label('Назначение')
                    ->badge(),
                TextColumn::make('provider')
                    ->label('Провайдер')
                    ->visibleFrom('md'),
                TextColumn::make('model')
                    ->label('Модель')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('called_at')
                    ->label('Время')
                    ->dateTime()
                    ->visibleFrom('md'),
                IconColumn::make('success')
                    ->label('Успех')
                    ->boolean(),
            ])
            ->defaultSort('called_at')
            ->recordAction('select')
            ->recordActions([
                Action::make('select')
                    ->label('Показать')
                    ->action(function (LlmCall $record): void {
                        $this->call = $record->physical_call_id;
                    }),
            ]);
    }

    public function selectedCall(): ?LlmCall
    {
        if ($this->call === null || ! Str::isUuid($this->call)) {
            return null;
        }

        return app(AnalysisCallReadRepository::class)->findInRun($this->call, $this->run);
    }

    public function requestPayload(LlmCall $call): StoredPayload
    {
        return StoredPayload::absent();
    }

    public function responsePayload(LlmCall $call): StoredPayload
    {
        return app(LlmPayloadReader::class)->receipt($call);
    }
}
