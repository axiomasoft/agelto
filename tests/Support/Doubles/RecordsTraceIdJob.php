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

/**
 * Тестовый job-двойник (P7.5): подтверждает, что `Context::add('trace_id', …)`, выставленный ДО
 * диспатча, доезжает до `handle()` через штатную дегидрацию/гидрацию `Illuminate\Log\Context`
 * (research/J §1) — пишет trace_id в JSON-канал лога, тест читает файл обратно.
 */
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
