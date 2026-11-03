<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler;

use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Exceptions\Procurement\Crawler\SourceControlConfigurationException;

/**
 * Типизированная `historical source-control fixture policy`: HTTP-статус → вид отказа и правила
 * каждого этапа. Разбирается и проверяется один раз (singleton, резолвится при старте в
 * `AppServiceProvider::boot`), дальше код работает только с enum и этим объектом.
 */
final readonly class SourceControlPolicy
{
    /**
     * @param  array<int, SourceFailureKind>  $statusKinds
     * @param  array<string, StagePolicy>  $stages  ключ — `SourceStage::value`, все этапы
     */
    private function __construct(
        private array $statusKinds,
        private SourceFailureKind $serverErrorKind,
        private SourceFailureKind $otherStatusKind,
        private array $stages,
    ) {}

    public function stage(SourceStage $stage): StagePolicy
    {
        return $this->stages[$stage->value];
    }

    /** Вид отказа для не-2xx ответа. */
    public function kindForStatus(int $status): SourceFailureKind
    {
        return $this->statusKinds[$status] ?? ($status >= 500 ? $this->serverErrorKind : $this->otherStatusKind);
    }

    public static function fromConfig(mixed $config): self
    {
        if (! is_array($config)) {
            throw SourceControlConfigurationException::at('', 'секция не задана.');
        }

        $statuses = $config['http_status_kinds'] ?? null;

        if (! is_array($statuses)) {
            throw SourceControlConfigurationException::at('http_status_kinds', 'ожидался массив «статус => SourceFailureKind».');
        }

        $statusKinds = [];

        foreach ($statuses as $status => $kind) {
            if (! is_int($status) || $status < 300 || $status > 599) {
                throw SourceControlConfigurationException::at("http_status_kinds.{$status}", 'ключ — HTTP-статус 300–599.');
            }

            $statusKinds[$status] = self::kind($kind, "http_status_kinds.{$status}");
        }

        $stagesConfig = $config['stages'] ?? null;

        if (! is_array($stagesConfig)) {
            throw SourceControlConfigurationException::at('stages', 'ожидался массив по этапам.');
        }

        foreach (array_keys($stagesConfig) as $key) {
            if (SourceStage::tryFrom((string) $key) === null) {
                throw SourceControlConfigurationException::at("stages.{$key}", 'неизвестный этап (см. SourceStage).');
            }
        }

        $stages = [];

        foreach (SourceStage::cases() as $stage) {
            if (! array_key_exists($stage->value, $stagesConfig)) {
                throw SourceControlConfigurationException::at('stages', "нет этапа «{$stage->value}».");
            }

            $stages[$stage->value] = StagePolicy::fromConfig($stagesConfig[$stage->value], "stages.{$stage->value}");
        }

        return new self(
            statusKinds: $statusKinds,
            serverErrorKind: self::kind($config['server_error_kind'] ?? null, 'server_error_kind'),
            otherStatusKind: self::kind($config['other_status_kind'] ?? null, 'other_status_kind'),
            stages: $stages,
        );
    }

    private static function kind(mixed $value, string $path): SourceFailureKind
    {
        if (! $value instanceof SourceFailureKind) {
            throw SourceControlConfigurationException::at($path, 'значение должно быть SourceFailureKind::*.');
        }

        return $value;
    }
}
