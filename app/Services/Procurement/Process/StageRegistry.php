<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Enums\Procurement\Process\StageCode;
use Illuminate\Container\Attributes\Singleton;

/**
 * Явная composition registry исполняющих handler versions (execution#s03/#s11). Identity handler
 * version — версия исполняющего кода, не control/epoch/generation. Регистрация разрешена только
 * schema-supported stage; пустая registry означает, что ни один handler не активен и admission
 * ничего не выдаёт. Реальные acquisition/AI handlers здесь ещё не регистрируются: P1.7 работает
 * на fixture handlers; handler instances и dispatch добавляет WorkRunner следующих пунктов.
 */
#[Singleton]
final class StageRegistry
{
    /** @var array<string, string> stage → handler version */
    private array $versions = [];

    public function register(StageCode $stage, string $handlerVersion): void
    {
        SchemaSupportedStages::ensureSupported($stage);
        if ($handlerVersion === '' || str_contains($handlerVersion, "\0")) {
            throw new \InvalidArgumentException('Handler version must be nonempty text.');
        }
        if (isset($this->versions[$stage->value]) && $this->versions[$stage->value] !== $handlerVersion) {
            throw new \LogicException('Stage already has an executing handler version: '.$stage->value);
        }
        $this->versions[$stage->value] = $handlerVersion;
    }

    public function handlerVersion(StageCode $stage): ?string
    {
        return $this->versions[$stage->value] ?? null;
    }
}
