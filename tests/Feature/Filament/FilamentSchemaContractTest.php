<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

test('все application schemas имеют операторскую Section', function (): void {
    $schemaSources = collect(File::allFiles(app_path('Filament/Resources')))
        ->filter(static fn (SplFileInfo $file): bool => str_contains($file->getPathname(), '/Schemas/'))
        ->map(static fn (SplFileInfo $file): string => $file->getContents())
        ->filter(static fn (string $source): bool => str_contains($source, 'function configure(Schema $schema)'));

    expect($schemaSources)->not->toBeEmpty();

    $schemaSources->each(function (string $source): void {
        expect($source)->toContain('Section::make(');
    });

    $flatFixture = <<<'PHP'
        <?php

        final class FlatSchema
        {
            public static function configure(Schema $schema): Schema
            {
                return $schema->components([
                    TextInput::make('name'),
                ]);
            }
        }
        PHP;

    expect(str_contains($flatFixture, 'Section::make('))->toBeFalse();
});

test('все поля application schemas имеют явную операторскую метку', function (): void {
    $componentClasses = implode('|', [
        'CheckboxList',
        'DatePicker',
        'FileUpload',
        'IconEntry',
        'KeyValue',
        'KeyValueEntry',
        'Placeholder',
        'RepeatableEntry',
        'Repeater',
        'Select',
        'TagsInput',
        'TextColumn',
        'TextEntry',
        'TextInput',
        'Textarea',
        'Toggle',
        'ViewEntry',
    ]);

    /** @return list<string> */
    $unlabelledComponents = static function (string $source, string $path) use ($componentClasses): array {
        $lines = preg_split('/\R/', $source) ?: [];
        $failures = [];

        foreach ($lines as $lineIndex => $line) {
            if (preg_match("/^(\\s*)(?:{$componentClasses})::make\\(/", $line, $component) !== 1) {
                continue;
            }

            $indent = strlen($component[1]);
            $declaration = $line;

            for ($nextIndex = $lineIndex + 1; $nextIndex < count($lines); $nextIndex++) {
                $nextLine = $lines[$nextIndex];

                if (
                    preg_match("/^(\\s*)(?:{$componentClasses})::make\\(/", $nextLine, $nextComponent) === 1
                    && strlen($nextComponent[1]) <= $indent
                ) {
                    break;
                }

                if (preg_match('/^(\s*)[\]\)]/', $nextLine, $closing) === 1 && strlen($closing[1]) < $indent) {
                    break;
                }

                $declaration .= "\n{$nextLine}";
            }

            if (! str_contains($declaration, '->label(') && ! str_contains($declaration, '->hiddenLabel(')) {
                $failures[] = "{$path}:".($lineIndex + 1).' '.trim($line);
            }
        }

        return $failures;
    };

    $failures = collect(File::allFiles(app_path('Filament/Resources')))
        ->filter(static fn (SplFileInfo $file): bool => str_contains($file->getPathname(), '/Schemas/'))
        ->flatMap(static fn (SplFileInfo $file): array => $unlabelledComponents(
            source: $file->getContents(),
            path: $file->getRelativePathname(),
        ))
        ->values()
        ->all();

    expect($failures)->toBe([]);

    $unlabelledFixture = <<<'PHP'
        TextInput::make('name')
            ->required(),
        PHP;

    expect($unlabelledComponents($unlabelledFixture, 'fixture.php'))->toHaveCount(1);
});
