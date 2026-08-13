<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\ListingSortField;
use App\Enums\Procurement\Crawler\RunStopPolicy;
use App\Enums\Procurement\Crawler\SourceMethod;
use App\Enums\Procurement\ProcurementStatus;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Filament\Support\LlmPresentation;
use Filament\Support\Contracts\HasLabel;

test('показывает разрешённые enum и LLM значения понятными русскими labels', function () {
    $enumClasses = [
        ProcurementStatus::class,
        CrawlerMode::class,
        RunStopPolicy::class,
        SourceMethod::class,
        ListingSortField::class,
        ProxyScope::class,
    ];

    foreach ($enumClasses as $enumClass) {
        expect(is_subclass_of($enumClass, HasLabel::class))->toBeTrue();

        foreach ($enumClass::cases() as $case) {
            expect($case->getLabel())->toBeString()->not->toBe('')->not->toBe($case->value);
        }
    }

    expect(LlmPresentation::documentTypeOptions())->toContain('Смета')
        ->and(LlmPresentation::promptStepOptions(steps: [\App\Enums\Llm\CorePromptStep::Global]))
        ->toBe(['global' => 'Общие правила']);
});
