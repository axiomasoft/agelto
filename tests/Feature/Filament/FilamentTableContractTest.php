<?php

declare(strict_types=1);

use App\Filament\Pages\FinalPromptPage;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\File;

/** @return iterable<class-string, Table> */
function filamentApplicationTables(): iterable
{
    foreach (File::allFiles(app_path('Filament')) as $file) {
        $directory = basename($file->getPath());
        if ($file->getExtension() !== 'php' || ! in_array($directory, ['Tables', 'RelationManagers'], strict: true)) {
            continue;
        }

        $class = 'App\\Filament\\'.str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));
        if (is_a($class, RelationManager::class, allow_string: true)) {
            $manager = new $class;

            yield $class => $manager->table(Table::make($manager));
        } elseif (is_callable([$class, 'configure'])) {
            yield $class => $class::configure(Table::make(Mockery::mock(HasTable::class)));
        }
    }
}

function filamentHasResponsiveColumns(Table $table): bool
{
    return collect($table->getColumns())->contains(
        fn (Column $column): bool => $column->getVisibleFrom() !== null || $column->isToggleable(),
    );
}

test('каждая витрина таблицы имеет mobile-правило для вторичных данных', function (): void {
    $tables = iterator_to_array(filamentApplicationTables());
    expect($tables)->not->toBeEmpty();

    foreach ($tables as $class => $table) {
        expect(filamentHasResponsiveColumns($table), $class)->toBeTrue();
    }

    $table = Table::make(Mockery::mock(HasTable::class))->columns([TextColumn::make('name')]);
    expect(filamentHasResponsiveColumns($table))->toBeFalse();
});

test('технические идентификаторы не занимают surface по умолчанию', function (): void {
    foreach (filamentApplicationTables() as $class => $table) {
        foreach (['uuid', 'entity_id'] as $name) {
            $column = $table->getColumn($name);
            if ($column !== null) {
                expect($column->isToggleable() && $column->isToggledHiddenByDefault(), "$class: $name")->toBeTrue();
            }
        }
    }
});

test('длинные значения не обрезаются без полного доступа', function (): void {
    $fullValue = str_repeat('Длинное значение. ', 50);

    foreach (filamentApplicationTables() as $class => $table) {
        foreach ($table->getColumns() as $column) {
            if ($column instanceof TextColumn && ($column->getCharacterLimit() !== null || $column->getLineClamp() !== null)) {
                expect($column->getTooltip($fullValue), "$class: {$column->getName()}")->toBe($fullValue);
            }
        }
    }
});

test('скрытая страница финального промта показывает boolean нативной колонкой', function (): void {
    $page = new FinalPromptPage;
    $table = $page->table(Table::make($page));
    $success = $table->getColumn('success');

    expect($success)->toBeInstanceOf(IconColumn::class)
        ->and($success->isBoolean())->toBeTrue()
        ->and($table->getColumn('provider')?->getVisibleFrom())->toBe('md');
});
