<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\PromptRule\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Platform\Filament\Resources\PromptRule\Concerns\HasRulesPreview;
use Platform\Filament\Resources\PromptRule\PromptRuleResource;

final class EditPromptRule extends EditRecord
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
                ->modalContent(fn (): \Illuminate\Contracts\View\View => view('platform::filament.prompt-rule.show-rules-content', [
                    'content' => $this->getRulesPreviewContentForStep(step: $this->record->step),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Закрыть')
                ->modalWidth('4xl'),
            Actions\DeleteAction::make(),
        ];
    }
}
