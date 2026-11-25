<?php

declare(strict_types=1);

namespace App\Support\Telemetry;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Logs\Log;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;

/**
 * Sentry before_send / before_send_transaction / before_send_log / before_breadcrumb hooks:
 * те же правила, что у log channels. Static callables, чтобы config кэшировался. Ошибка очистки
 * отбрасывает событие целиком, а не отправляет его сырым.
 */
final class SentrySanitizer
{
    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        return self::sanitizeEvent($event);
    }

    public static function beforeSendTransaction(Event $event, ?EventHint $hint = null): ?Event
    {
        return self::sanitizeEvent($event);
    }

    public static function beforeSendLog(Log $log): ?Log
    {
        try {
            $sanitizer = new TelemetrySanitizer;
            $safe = new Log(
                timestamp: $log->getTimestamp(),
                traceId: $log->getTraceId(),
                level: $log->getLevel(),
                body: $sanitizer->text($log->getBody()),
            );

            foreach ($sanitizer->stringContext($log->attributes()->toSimpleArray()) as $key => $value) {
                $safe->setAttribute(key: $sanitizer->text($key), value: $value);
            }

            return $safe;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        try {
            return self::rebuildBreadcrumb($breadcrumb, new TelemetrySanitizer);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function sanitizeEvent(Event $event): ?Event
    {
        try {
            $sanitizer = new TelemetrySanitizer;
            $message = $event->getMessage();
            if ($message !== null) {
                $event->setMessage($sanitizer->exceptionMessage($message));
            }
            $event->setExtra($sanitizer->stringContext($event->getExtra()));
            $event->setTags(self::sanitizeTags($event->getTags(), $sanitizer));
            foreach ($event->getContexts() as $name => $data) {
                $event->setContext((string) $name, $sanitizer->stringContext($data));
            }
            // Request carries raw URL/headers/cookies/body: оставляем только метод и безопасный URL.
            $request = $event->getRequest();
            $event->setRequest(array_filter([
                'method' => isset($request['method']) && is_string($request['method']) ? $request['method'] : null,
                'url' => isset($request['url']) && is_string($request['url']) ? $sanitizer->url($request['url']) : null,
            ], static fn (?string $value): bool => $value !== null));
            foreach ($event->getExceptions() as $exception) {
                $exception->setValue($sanitizer->exceptionMessage($exception->getValue()));
                $stacktrace = $exception->getStacktrace();
                if ($stacktrace !== null) {
                    foreach ($stacktrace->getFrames() as $frame) {
                        $frame->setVars($sanitizer->stringContext($frame->getVars()));
                    }
                }
                $mechanism = $exception->getMechanism();
                if ($mechanism !== null) {
                    $mechanism->setData($sanitizer->stringContext($mechanism->getData()));
                }
            }
            $event->setSpans(array_map(
                static fn (Span $span): Span => self::rebuildSpan($span, $sanitizer),
                $event->getSpans(),
            ));
            $event->setBreadcrumb(array_values(array_map(
                static fn (Breadcrumb $breadcrumb): Breadcrumb => self::rebuildBreadcrumb($breadcrumb, $sanitizer),
                $event->getBreadcrumbs(),
            )));

            return $event;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Span::setData merges rather than replaces; rebuild so fields past the bound cannot survive. */
    private static function rebuildSpan(Span $span, TelemetrySanitizer $sanitizer): Span
    {
        $description = $span->getDescription();
        $context = (new SpanContext)
            ->setTraceId($span->getTraceId())
            ->setSpanId($span->getSpanId())
            ->setParentSpanId($span->getParentSpanId())
            ->setStartTimestamp($span->getStartTimestamp())
            ->setEndTimestamp($span->getEndTimestamp())
            ->setDescription($description === null ? null : $sanitizer->exceptionMessage($description))
            ->setOp($span->getOp())
            ->setStatus($span->getStatus())
            ->setSampled($span->getSampled())
            ->setOrigin($span->getOrigin())
            ->setTags(self::sanitizeTags($span->getTags(), $sanitizer))
            ->setData($sanitizer->stringContext($span->getData()));

        return new Span($context);
    }

    /**
     * @param  array<string, string>  $tags
     * @return array<string, string>
     */
    private static function sanitizeTags(array $tags, TelemetrySanitizer $sanitizer): array
    {
        $safe = [];
        foreach ($sanitizer->stringContext($tags) as $key => $value) {
            if (is_string($value)) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /**
     * withMetadata дописывает ключи поверх исходного breadcrumb и не удаляет хвост за лимитом полей.
     * Новый breadcrumb получает только уже санитизированную карту.
     */
    private static function rebuildBreadcrumb(Breadcrumb $breadcrumb, TelemetrySanitizer $sanitizer): Breadcrumb
    {
        $metadata = [];
        foreach ($sanitizer->context($breadcrumb->getMetadata()) as $name => $value) {
            $metadata[(string) $name] = $value;
        }
        $message = $breadcrumb->getMessage();

        return new Breadcrumb(
            $breadcrumb->getLevel(),
            $breadcrumb->getType(),
            $breadcrumb->getCategory(),
            $message === null ? null : $sanitizer->exceptionMessage($message),
            $metadata,
            $breadcrumb->getTimestamp(),
        );
    }
}
