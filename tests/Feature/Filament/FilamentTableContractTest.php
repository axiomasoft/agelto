<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

function filamentTableSources(): array
{
    return collect(File::allFiles(app_path('Filament')))
        ->filter(fn (SplFileInfo $file): bool => str($file->getPathname())->contains(['/Tables/', '/RelationManagers/']))
        ->mapWithKeys(fn (SplFileInfo $file): array => [$file->getRelativePathname() => $file->getContents()])
        ->all();
}

test('каждая витрина таблицы имеет mobile-правило для вторичных данных', function (): void {
    $sources = filamentTableSources();

    expect($sources)->not->toBeEmpty();

    foreach ($sources as $path => $source) {
        expect($source, $path)->toContain('->columns([')
            ->toMatch('/->(?:visibleFrom|toggleable)\(/');
    }
});

test('технические идентификаторы не занимают surface по умолчанию', function (): void {
    foreach (filamentTableSources() as $path => $source) {
        if (! str($source)->contains(["TextColumn::make('uuid')", "TextColumn::make('entity_id')"])) {
            continue;
        }

        expect($source, $path)->toMatch('/(?:uuid|entity_id).*?->toggleable\(isToggledHiddenByDefault: true\)/s');
    }
});

test('длинные значения не обрезаются без полного доступа', function (): void {
    foreach (filamentTableSources() as $path => $source) {
        expect($source, $path)->not->toMatch('/->limit\([^)]*\)(?![\s\S]{0,180}->tooltip\()/');
    }
});

test('скрытая страница финального промта показывает boolean нативной колонкой', function (): void {
    $source = File::get(app_path('Filament/Pages/FinalPromptPage.php'));

    expect($source)->toContain("IconColumn::make('success')")
        ->toContain("->visibleFrom('md')")
        ->not->toContain("TextColumn::make('success')");
});
