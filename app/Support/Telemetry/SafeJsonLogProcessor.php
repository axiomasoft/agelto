<?php

declare(strict_types=1);

namespace App\Support\Telemetry;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor каждого JSON-канала (observability#s05): message, context и extra (включая
 * Laravel Context) проходят TelemetrySanitizer до formatter/handler. Ошибка санитайзера не
 * пропускает сырую запись и не ломает вызывающий код: запись заменяется безопасным маркером.
 */
final readonly class SafeJsonLogProcessor implements ProcessorInterface
{
    public function __construct(private TelemetrySanitizer $sanitizer) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        try {
            return $record->with(
                message: $this->sanitizer->text($record->message),
                context: $this->sanitizer->context($record->context),
                extra: $this->sanitizer->context($record->extra),
            );
        } catch (\Throwable) {
            return $record->with(message: TelemetrySanitizer::OMITTED, context: ['sanitizer_failed' => true], extra: []);
        }
    }
}
