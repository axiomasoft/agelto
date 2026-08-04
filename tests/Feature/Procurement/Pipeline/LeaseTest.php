<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AnalysisStep;
use App\Jobs\Procurement\PipelineJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use Illuminate\Support\Carbon;

test('двойной acquireLease на Document — второй захват возвращает false', function () {
    $document = Document::factory()->create();

    $first = $document->acquireLease(owner: 'worker-1');
    $second = $document->fresh()->acquireLease(owner: 'worker-2');

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse();

    $document->refresh();
    expect($document->locked_by)->toBe('worker-1');
});

test('releaseLease на Document освобождает лиз — следующий acquireLease проходит', function () {
    $document = Document::factory()->create();
    $document->acquireLease(owner: 'worker-1');

    $document->releaseLease();
    $document->refresh();

    expect($document->locked_by)->toBeNull()
        ->and($document->locked_until)->toBeNull();

    $reacquired = $document->acquireLease(owner: 'worker-2');
    expect($reacquired)->toBeTrue();
});

test('acquireLease на Document проходит после истечения TTL просроченного лиза', function () {
    $document = Document::factory()->create();
    $document->forceFill(['locked_by' => 'stale-worker', 'locked_until' => now()->subMinute()])->save();

    $acquired = $document->fresh()->acquireLease(owner: 'worker-2');

    expect($acquired)->toBeTrue();
});

test('двойной acquireLease на Ai\Analysis — второй захват возвращает false', function () {
    $analysis = Analysis::factory()->create(['step' => AnalysisStep::Screening]);

    $first = $analysis->acquireLease(owner: 'worker-1');
    $second = $analysis->fresh()->acquireLease(owner: 'worker-2');

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse();
});

test('recordFailure на Document считает попытки и экспоненциальный backoff с потолком', function () {
    config(['ai.retry.max_attempts' => 5, 'ai.retry.max_backoff_minutes' => 240]);
    Carbon::setTestNow('2026-07-08 12:00:00');

    $document = Document::factory()->create();

    $document->recordFailure('первая ошибка');
    expect($document->attempts)->toBe(1)
        ->and($document->failed_permanently)->toBeFalse()
        ->and((int) $document->next_retry_at->diffInMinutes(now(), absolute: true))->toBe(2);

    $document->recordFailure('вторая ошибка');
    expect($document->attempts)->toBe(2)
        ->and((int) $document->next_retry_at->diffInMinutes(now(), absolute: true))->toBe(4);

    Carbon::setTestNow();
});

test('recordFailure помечает failed_permanently по достижении max_attempts, backoff упирается в потолок', function () {
    config(['ai.retry.max_attempts' => 3, 'ai.retry.max_backoff_minutes' => 5]);

    $document = Document::factory()->create(['attempts' => 1]);

    $document->recordFailure('ошибка близко к потолку'); // attempts=2, 2**2=4 < 5
    expect($document->attempts)->toBe(2)
        ->and($document->failed_permanently)->toBeFalse();

    $document->recordFailure('последняя ошибка'); // attempts=3 >= max_attempts=3
    expect($document->attempts)->toBe(3)
        ->and($document->failed_permanently)->toBeTrue()
        ->and($document->next_retry_at)->toBeNull();
});

test('PipelineJob: провальный status-guard не захватывает лиз и не выполняет process', function () {
    $calls = ['acquireLease' => 0, 'process' => 0, 'recordFailure' => 0, 'releaseLease' => 0];

    $job = new class($calls) extends PipelineJob
    {
        public function __construct(private array &$calls) {}

        protected function statusGuardPassed(): bool
        {
            return false;
        }

        protected function acquireLease(): bool
        {
            $this->calls['acquireLease']++;

            return true;
        }

        protected function releaseLease(): void
        {
            $this->calls['releaseLease']++;
        }

        protected function recordFailure(Throwable $exception): void
        {
            $this->calls['recordFailure']++;
        }

        protected function process(): void
        {
            $this->calls['process']++;
        }

        public function pipelineUniqueId(): string
        {
            return '1:test';
        }
    };

    $job->handle();

    expect($calls['acquireLease'])->toBe(0)
        ->and($calls['process'])->toBe(0)
        ->and($calls['releaseLease'])->toBe(0);
});

test('PipelineJob: непройденный acquireLease не выполняет process и не освобождает лиз', function () {
    $calls = ['process' => 0, 'recordFailure' => 0, 'releaseLease' => 0];

    $job = new class($calls) extends PipelineJob
    {
        public function __construct(private array &$calls) {}

        protected function statusGuardPassed(): bool
        {
            return true;
        }

        protected function acquireLease(): bool
        {
            return false;
        }

        protected function releaseLease(): void
        {
            $this->calls['releaseLease']++;
        }

        protected function recordFailure(Throwable $exception): void
        {
            $this->calls['recordFailure']++;
        }

        protected function process(): void
        {
            $this->calls['process']++;
        }

        public function pipelineUniqueId(): string
        {
            return '1:test';
        }
    };

    $job->handle();

    expect($calls['process'])->toBe(0)
        ->and($calls['releaseLease'])->toBe(0);
});

test('PipelineJob: исключение из process пишет recordFailure, освобождает лиз в finally и пробрасывается дальше', function () {
    $calls = ['recordFailure' => 0, 'releaseLease' => 0];

    $job = new class($calls) extends PipelineJob
    {
        public function __construct(private array &$calls) {}

        protected function statusGuardPassed(): bool
        {
            return true;
        }

        protected function acquireLease(): bool
        {
            return true;
        }

        protected function releaseLease(): void
        {
            $this->calls['releaseLease']++;
        }

        protected function recordFailure(Throwable $exception): void
        {
            $this->calls['recordFailure']++;
        }

        protected function process(): void
        {
            throw new RuntimeException('провал стадии');
        }

        public function pipelineUniqueId(): string
        {
            return '1:test';
        }
    };

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'провал стадии');

    expect($calls['recordFailure'])->toBe(1)
        ->and($calls['releaseLease'])->toBe(1);
});

test('PipelineJob: успешный process освобождает лиз в finally', function () {
    $calls = ['process' => 0, 'releaseLease' => 0];

    $job = new class($calls) extends PipelineJob
    {
        public function __construct(private array &$calls) {}

        protected function statusGuardPassed(): bool
        {
            return true;
        }

        protected function acquireLease(): bool
        {
            return true;
        }

        protected function releaseLease(): void
        {
            $this->calls['releaseLease']++;
        }

        protected function recordFailure(Throwable $exception): void
        {
            //
        }

        protected function process(): void
        {
            $this->calls['process']++;
        }

        public function pipelineUniqueId(): string
        {
            return '1:test';
        }
    };

    $job->handle();

    expect($calls['process'])->toBe(1)
        ->and($calls['releaseLease'])->toBe(1);
});
