<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\Support\Doubles\RecordsTraceIdJob;

function jsonLogPath(): string
{
    return (string) config('logging.channels.json_file.handler_with.stream');
}

beforeEach(function () {
    File::delete(jsonLogPath());
});

afterEach(function () {
    File::delete(jsonLogPath());
});

test('json_file-канал пишет запись в формате JSON с Context-полями в extra', function () {
    Context::add('trace_id', 'json-channel-test-trace');

    Log::channel('json_file')->info('наблюдаемость: тестовое сообщение');

    $line = trim(File::get(jsonLogPath()));
    $decoded = json_decode($line, associative: true);

    expect($decoded)->toBeArray()
        ->and($decoded['message'])->toBe('наблюдаемость: тестовое сообщение')
        ->and($decoded['extra']['trace_id'] ?? null)->toBe('json-channel-test-trace');
});

test('trace_id, выставленный ДО dispatch, доезжает до job через реальную дегидрацию/гидрацию Context', function () {
    // `database`-очередь (не `sync` из phpunit.xml) — `queue:work` реально сбрасывает scoped-
    // инстансы (`QueueServiceProvider::resetScope()`, включая `Context`) ПЕРЕД обработкой job'а,
    // как настоящий воркер; только так тест доказывает дегидрацию/гидрацию, а не «значение и
    // так осталось в том же PHP-процессе» (`dispatchSync()`/`sync`-очередь этого не проверяют).
    config(['queue.default' => 'database']);

    Context::add('trace_id', 'job-dispatch-test-trace');

    RecordsTraceIdJob::dispatch();

    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

    $line = collect(explode(PHP_EOL, trim(File::get(jsonLogPath()))))->last();
    $decoded = json_decode($line, associative: true);

    expect($decoded['context']['observed_trace_id'] ?? null)->toBe('job-dispatch-test-trace');
});
