<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromptRule\Pages;

use Filament\Actions;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use App\Dto\Prompt\Step;
use App\Filament\Resources\PromptRule\Concerns\HasRulesPreview;
use App\Filament\Resources\PromptRule\PromptRuleResource;

final class ListPromptRules extends ListRecords
{
    use HasRulesPreview;

    protected static string $resource = PromptRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('showRules')
                ->label('Показать правила')
                ->icon(Heroicon::DocumentText)
                ->modalHeading('Правила для шага')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Закрыть')
                ->modalWidth('4xl')
                ->schema([
                    Select::make('step')
                        ->label('Шаг')
                        ->options(Step::options())
                        ->required()
                        ->live()
                        ->native(false),
                    Hidden::make('previewContent'),
                    View::make('filament.prompt-rule.show-rules-pre-result')
                        ->viewData(fn (Get $get): array => [
                            'content' => $get('previewContent'),
                        ]),
                ])
                ->extraModalFooterActions([
                    Actions\Action::make('loadPreview')
                        ->label('Показать правила')
                        ->disabled(function (array $mountedActions): bool {
                            $data = $mountedActions[0]->getRawData();

                            return empty($data['step'] ?? null);
                        })
                        ->action(function (array $mountedActions): void {
                            $data = $mountedActions[0]->getRawData();
                            $step = Step::tryFrom($data['step'] ?? '');
                            if ($step === null) {
                                return;
                            }
                            $content = $this->getRulesPreviewContentForStep(step: $step);
                            $data['previewContent'] = $content;
                            $this->mountedActions[0]['data'] = $data;
                        }),
                ]),
            Actions\CreateAction::make(),
        ];
    }
}
