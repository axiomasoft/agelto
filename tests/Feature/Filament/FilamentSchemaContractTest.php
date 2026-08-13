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
