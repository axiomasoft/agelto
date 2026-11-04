<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\StageOutcome;
use App\Enums\Procurement\Process\StageCode;
use App\Services\Procurement\Process\SubjectLockResolver;
use Symfony\Component\Finder\Finder;
use Tests\Support\ProcessCore\ProcessSourceRules;

pest()->group('domain-dependency-boundary');

test('Process contracts и механика сохраняют direction strict types и выбранные readonly value types', function (): void {
    $violations = [];
    $count = 0;
    foreach (['Dto', 'Enums', 'Services', 'Repositories', 'Models', 'Jobs', 'Actions'] as $layer) {
        $root = app_path($layer.'/Procurement/Process');
        if (! is_dir($root)) {
            continue;
        }
        foreach (Finder::create()->files()->in($root)->name('*.php') as $file) {
            $path = 'app/'.$layer.'/Procurement/Process/'.$file->getRelativePathname();
            $violations = [...$violations, ...ProcessSourceRules::violations($path, $file->getContents())];
            $count++;
        }
    }
    expect($count)->toBeGreaterThan(0)->and($violations)->toBe([]);
});

test('StageOutcome имеет ровно пять final readonly вариантов без cancelled и superseded', function (): void {
    $variants = [];
    foreach (Finder::create()->files()->in(app_path('Dto/Procurement/Process'))->name('*.php') as $file) {
        $class = 'App\Dto\Procurement\Process\\'.$file->getBasename('.php');
        if (class_exists($class) && is_subclass_of($class, StageOutcome::class)) {
            $reflection = new ReflectionClass($class);
            expect($reflection->isFinal())->toBeTrue()->and($reflection->isReadOnly())->toBeTrue();
            $variants[] = $reflection->getShortName();
        }
    }
    sort($variants);
    expect($variants)->toBe(['StageBlocked', 'StageDeferred', 'StageFailedItem', 'StagePartial', 'StageSucceeded']);
});

test('negative source fixtures обнаруживают imports FQCN strings branching strict и mutable values', function (): void {
    $path = 'app/Services/Procurement/Process/WorkRunner.php';
    $header = "<?php\ndeclare(strict_types=1);\n";
    $dependency = 'App\Services\Platforms\Eis\Card\EisDetailParser';
    $fixtures = [
        [$path, $header.'use '.$dependency.';', 'forbidden process dependency: '.$path.' -> '.$dependency],
        [$path, $header.'new \\'.$dependency.';', 'forbidden process dependency: '.$path.' -> '.$dependency],
        [$path, $header."\$class = '".str_replace('\\', '\\\\', $dependency)."';", 'forbidden process dependency: '.$path.' -> '.$dependency],
        [$path, $header.'use App\Services\Llm\Providers\GeminiGenerateContentClient;', 'forbidden process dependency: '.$path.' -> App\Services\Llm\Providers\GeminiGenerateContentClient'],
        [$path, $header.'use App\Services\Procurement\Extraction\ExtractionHandler;', 'forbidden process dependency: '.$path.' -> App\Services\Procurement\Extraction\ExtractionHandler'],
        [$path, $header.'use App\Enums\Procurement\Process\{StageCode};', 'group use is not supported: '.$path],
        [$path, $header.'match ($work->stage) { default => 1 };', 'stage handler branching: '.$path],
        [$path, $header.'if ($stage === StageCode::Listing) {}', 'stage handler branching: '.$path],
        [$path, $header.'use App\Enums\Procurement\Process\StageCode as S; switch (S::Listing) {}', 'stage handler branching: '.$path],
        [$path, '<?php class WorkRunner {}', 'strict types required: '.$path],
        ['app/Dto/Procurement/Process/WorkId.php', $header.'final class WorkId {}', 'value type must be final readonly: app/Dto/Procurement/Process/WorkId.php'],
        ['app/Dto/Procurement/Platform/Definition.php', $header.'class Definition {}', 'value type must be final readonly: app/Dto/Procurement/Platform/Definition.php'],
        ['app/Dto/Procurement/Acquisition/Snapshot.php', $header.'readonly class Snapshot {}', 'value type must be final readonly: app/Dto/Procurement/Acquisition/Snapshot.php'],
    ];
    $fixture = tempnam(sys_get_temp_dir(), 'process-t44-');
    try {
        foreach ($fixtures as [$fixturePath, $source, $expected]) {
            file_put_contents($fixture, $source);
            expect(ProcessSourceRules::violations($fixturePath, file_get_contents($fixture)))->toContain($expected);
        }
    } finally {
        unlink($fixture);
    }
});

test('application seams и mutable ExecutionContext или Spatie Data не попадают под blanket readonly', function (): void {
    $header = "<?php\ndeclare(strict_types=1);\n";
    foreach ([
        ['app/Dto/Procurement/Process/ExecutionContext.php', $header.'final class ExecutionContext {}'],
        ['app/Dto/Procurement/Acquisition/ExistingData.php', $header.'use Spatie\LaravelData\Data; class ExistingData extends Data {}'],
        ['app/Services/Procurement/Process/StageRegistry.php', $header.'use App\Services\Procurement\Extraction\ExtractionHandler;'],
        ['app/Services/Procurement/Process/SubjectLockResolver.php', $header.'match ($input->stage) { default => 1 };'],
        ['app/Services/Procurement/Process/ProcessPayloadCodec.php', $header.'match ($stage) { default => 1 };'],
        ['app/Services/Procurement/Process/WorkRunner.php', $header.'// if ($stage === StageCode::Listing)'."\n".'$value = 1;'],
    ] as [$path, $source]) {
        expect(ProcessSourceRules::violations($path, $source))->toBe([]);
    }
});

test('analysis storage lock membership belongs to the typed subject composition seam', function (): void {
    $resolver = new SubjectLockResolver;
    foreach (StageCode::cases() as $stage) {
        expect($resolver->requiresAnalysisStorageLocks($stage))->toBe(in_array($stage->value, ['screening', 'extraction', 'publication'], true));
    }
});
