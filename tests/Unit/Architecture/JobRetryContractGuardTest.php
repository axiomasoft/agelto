<?php

declare(strict_types=1);

use App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob;
use App\Jobs\Procurement\Process\ExecuteWorkJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Symfony\Component\Finder\Finder;

/*
 * Сторож Д-2/Д-3 (P7.7, research/10 §3, D39): ретрай сознательно вынесен ИЗ очереди
 * (`tries: 1` во всех supervisor'ах Horizon) в доменный контур — `HasErrorBlock`
 * (`attempts`/`next_retry_at`/`failed_permanently`) + расписанный `pipeline:retry-failed`.
 * Решение нигде не было записано как решение (Д-2) — следующий разработчик читал `tries: 1`
 * как недосмотр и «чинил» бы его свойствами ретрая параллельно существующему контуру. Это
 * D-запись + два сторожа: свойства очередного ретрая на job'ах Procurement появляться не
 * должны (иначе стадия ретраится ДВАЖДЫ — очередью и `pipeline:retry-failed`), а
 * `EstimateExtractionJob` не должен потерять `ShouldBeUniqueUntilProcessing` — под простым
 * `ShouldBeUnique` self-dispatch продолжения (checkpoint-requeue) тихо дедуплицируется, пока
 * текущий запуск ещё держит lock, и документ навсегда зависает в extraction (D39).
 */

/** @return list<string> */
function procurementJobFiles(): array
{
    $files = [];

    foreach (Finder::create()->files()->in(app_path('Jobs/Procurement'))->name('*.php') as $file) {
        $files[] = $file->getRealPath();
    }

    return $files;
}

test('ни один job Procurement не объявляет свойства очередного ретрая — контур единственный, в HasErrorBlock (D2/D39)', function (): void {
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
