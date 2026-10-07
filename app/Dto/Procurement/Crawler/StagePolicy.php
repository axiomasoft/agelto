<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler;

use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceFailureReaction;
use App\Exceptions\Procurement\Crawler\SourceControlConfigurationException;

/**
 * Правила одного этапа: реакция на каждый вид отказа, пороги и интервал ожидания. Строится
 * только из `SourceControlPolicy::fromConfig()`; реакции заданы для всех видов (проверено).
 */
final readonly class StagePolicy
{
    /** @param array<string, SourceFailureReaction> $reactions ключ — `SourceFailureKind::value`, все виды */
    private function __construct(
        public int $failureThreshold,
        public int $layoutThreshold,
        public int $retryIntervalMinutes,
        public ?int $maxConsecutiveSkips,
        private array $reactions,
    ) {}

    public function reactionFor(SourceFailureKind $kind): SourceFailureReaction
    {
        return $this->reactions[$kind->value];
    }

    /**
     * Порогам из конфига — переопределения площадки из админки (`null` — не задано).
     * Единственное место, где решается приоритет «БД над конфигом».
     */
    public function withOverrides(?int $failureThreshold, ?int $layoutThreshold, ?int $retryIntervalMinutes): self
    {
        return new self(
            failureThreshold: $failureThreshold ?? $this->failureThreshold,
            layoutThreshold: $layoutThreshold ?? $this->layoutThreshold,
            retryIntervalMinutes: $retryIntervalMinutes ?? $this->retryIntervalMinutes,
            maxConsecutiveSkips: $this->maxConsecutiveSkips,
            reactions: $this->reactions,
        );
    }

    public static function fromConfig(mixed $config, string $path): self
    {
        if (! is_array($config)) {
            throw SourceControlConfigurationException::at($path, 'ожидался массив.');
        }

        $reactions = $config['reactions'] ?? null;

        if (! is_array($reactions)) {
            throw SourceControlConfigurationException::at("{$path}.reactions", 'ожидался массив «вид отказа => SourceFailureReaction».');
        }

        $parsed = [];

        foreach ($reactions as $kind => $reaction) {
            if (SourceFailureKind::tryFrom((string) $kind) === null) {
                throw SourceControlConfigurationException::at("{$path}.reactions.{$kind}", 'неизвестный вид отказа (см. SourceFailureKind).');
            }

            if (! $reaction instanceof SourceFailureReaction) {
                throw SourceControlConfigurationException::at("{$path}.reactions.{$kind}", 'значение должно быть SourceFailureReaction::*.');
            }

            $parsed[(string) $kind] = $reaction;
        }

        foreach (SourceFailureKind::cases() as $kind) {
            if (! isset($parsed[$kind->value])) {
                throw SourceControlConfigurationException::at("{$path}.reactions", "не задана реакция для «{$kind->value}» — нужны все виды отказа.");
            }
        }

        return new self(
            failureThreshold: self::positiveInt($config, 'failure_threshold', $path),
            layoutThreshold: self::positiveInt($config, 'layout_threshold', $path),
            retryIntervalMinutes: self::positiveInt($config, 'retry_interval_minutes', $path),
            maxConsecutiveSkips: ($config['max_consecutive_skips'] ?? null) === null ? null : self::positiveInt($config, 'max_consecutive_skips', $path),
            reactions: $parsed,
        );
    }

    /** @param array<mixed> $config */
    private static function positiveInt(array $config, string $key, string $path): int
    {
        $value = $config[$key] ?? null;

        if (! is_int($value) || $value < 1) {
            throw SourceControlConfigurationException::at("{$path}.{$key}", 'ожидалось целое ≥ 1.');
        }

        return $value;
    }
}
