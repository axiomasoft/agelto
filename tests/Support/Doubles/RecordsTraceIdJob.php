<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/** Тестовый job записывает переданный через контекст trace_id в JSON-лог. */
final class RecordsTraceIdJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Log::channel('json_file')->info('RecordsTraceIdJob: обработан', [
            'observed_trace_id' => Context::get('trace_id'),
        ]);
    }
}
