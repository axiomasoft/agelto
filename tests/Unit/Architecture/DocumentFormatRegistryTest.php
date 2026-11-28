<?php

declare(strict_types=1);

use App\Enums\Procurement\Document\DocumentFormat;
use App\Services\Procurement\Prepare\DocumentFormatRegistry;
use Symfony\Component\Finder\Finder;
use Tests\Support\Documents\DocumentFormatContract;

test('закрытый registry покрывает каждый DocumentFormat ровно одним handler и evidence-vector', function (): void {
    $registry = app(DocumentFormatRegistry::class);
    $contract = DocumentFormatContract::cases();

    expect(array_keys($contract))->toEqualCanonicalizing(array_map(fn (DocumentFormat $format): string => $format->value, DocumentFormat::cases()));

    foreach ($contract as $format => $case) {
        foreach ($case['extensions'] as $extension) {
            $entry = $registry->forExtension($extension);

            expect($entry)->not->toBeNull()
                ->and($entry['format']->value)->toBe($format)
                ->and($entry['strategy'])->toBe($case['strategy'])
                ->and($entry['result'])->toBe($case['result'])
                ->and($entry['mimes'])->toEqualCanonicalizing($case['mimes'])
                ->and($entry['mimes'])->not->toContain($case['mismatch']);
        }
    }
});

test('расширение маршрутизируется только registry или format-specific probe, без catch-all strategy', function (): void {
    $violations = [];

    foreach (Finder::create()->files()->in(app_path('Services/Procurement/Prepare'))->name('*.php') as $file) {
        $path = $file->getRealPath();
        $source = (string) file_get_contents($path);

        if (str_contains($source, 'canProcess')) {
            $violations[] = $path;
        }
    }

    expect($violations)->toBe([]);
});
