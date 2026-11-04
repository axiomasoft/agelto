<?php

declare(strict_types=1);

use App\Jobs\Procurement\Process\ExecuteWorkJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Symfony\Component\Finder\Finder;

/** Проверяет единственный Process delivery и отсутствие прежних очередных механизмов. */

/** @return list<string> */
function procurementJobFiles(): array
{
    $files = [];

    foreach (Finder::create()->files()->in(app_path('Jobs/Procurement'))->name('*.php') as $file) {
        $files[] = $file->getRealPath();
    }

    return $files;
}

test('ни один job Procurement не объявляет свойства очередного ретрая — контур единственный, в HasErrorBlock', function (): void {
    $violations = [];

    foreach (procurementJobFiles() as $path) {
        $source = (string) file_get_contents($path);

        if (preg_match('/\$tries\s*=|\$backoff\s*=|\$maxExceptions\s*=|function\s+retryUntil\s*\(|function\s+failOnTimeout\s*\(/', $source) === 1) {
            $violations[] = $path;
        }
    }

    expect($violations)->toBe([]);
});

test('AI continuation uses the sole Process delivery without queue uniqueness middleware', function (): void {
    expect(is_file(app_path('Jobs/Procurement/Analyzers/Estimate/EstimateExtractionJob.php')))->toBeFalse();
    $interfaces = class_implements(ExecuteWorkJob::class);
    expect($interfaces)->not->toHaveKey(ShouldBeUniqueUntilProcessing::class)
        ->not->toHaveKey(ShouldBeUnique::class);
});
