<?php

declare(strict_types=1);

use App\Filament\Resources\Procurement\Ai\Fixtures\Schemas\RunResultSchema;
use App\Filament\Resources\Procurement\Ai\PromptSuggestions\Schemas\PromptSuggestionInfolist;
use App\Filament\Resources\Procurement\Procurements\Schemas\ProcurementInfolist;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Ai\PromptSuggestion;
use App\Models\Procurement\Procurement;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\Entry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\File;
use Livewire\Component as LivewireComponent;

function filamentTestSchema(): Schema
{
    $livewire = new class extends LivewireComponent implements HasSchemas
    {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };

    return Schema::make($livewire)->statePath('data');
}

/** @return iterable<class-string, Schema> */
function filamentApplicationSchemas(): iterable
{
    foreach (File::allFiles(app_path('Filament/Resources')) as $file) {
        if ($file->getExtension() !== 'php' || basename($file->getPath()) !== 'Schemas') {
            continue;
        }

        $class = 'App\\Filament\\Resources\\'.str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));
        if (! is_callable([$class, 'configure'])) {
            continue;
        }

        $schema = filamentTestSchema();
        yield $class => match ($class) {
            ProcurementInfolist::class => $class::configure($schema->record(new Procurement)),
            PromptSuggestionInfolist::class => $class::configure($schema->record(new PromptSuggestion)),
            RunResultSchema::class => $class::configure(
                schema: $schema,
                fixture: new Fixture,
                run: (new AnalysisRun)->setRelation('analyses', collect()),
            ),
            default => $class::configure($schema),
        };
    }
}

function filamentSchemaHasOperatorSection(Schema $schema): bool
{
    return collect($schema->getComponents(withActions: false))
        ->contains(fn (Component $component): bool => $component instanceof Section);
}

/** @return list<string> */
function filamentUnlabelledFields(Schema $schema): array
{
    $failures = [];
    foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
        if (($component instanceof Field || $component instanceof Entry)
            && ! $component instanceof Hidden
            && ! $component->hasCustomLabel()
            && ! $component->isLabelHidden()) {
            $failures[] = $component->getName();
        }

        $child = $component->getChildSchema();
        if ($child !== null) {
            $failures = [...$failures, ...filamentUnlabelledFields($child)];
        }
    }

    return $failures;
}

test('все application schemas имеют операторскую Section', function (): void {
    $schemas = iterator_to_array(filamentApplicationSchemas());
    expect($schemas)->not->toBeEmpty();

    foreach ($schemas as $class => $schema) {
        expect(filamentSchemaHasOperatorSection($schema), $class)->toBeTrue();
    }

    expect(filamentSchemaHasOperatorSection(filamentTestSchema()->components([TextInput::make('name')])))->toBeFalse();
    expect(filamentSchemaHasOperatorSection(filamentTestSchema()->components([Section::make('Контакты')->hidden()])))->toBeFalse();
});

test('все поля application schemas имеют явную операторскую метку', function (): void {
    $unlabelledFields = [];
    foreach (filamentApplicationSchemas() as $class => $schema) {
        foreach (filamentUnlabelledFields($schema) as $field) {
            $unlabelledFields[] = "$class: $field";
        }
    }
    expect($unlabelledFields)->toBe([]);

    $unlabelled = filamentTestSchema()->components([
        Section::make('Контакты')->schema([TextInput::make('name')]),
    ]);

    expect(filamentUnlabelledFields($unlabelled))->toBe(['name']);
});
