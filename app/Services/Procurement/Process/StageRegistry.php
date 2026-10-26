<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Enums\Procurement\Process\StageCode;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Services\Procurement\Process\Contracts\StageResultRepository;
use Illuminate\Container\Attributes\Singleton;

/**
 * Явная composition registry исполняющих handler versions (execution#s03/#s11). Identity handler
 * version — версия исполняющего кода, не control/epoch/generation. Регистрация разрешена только
 * schema-supported stage; пустая registry означает, что ни один handler не активен и admission
 * ничего не выдаёт. Реальные acquisition/AI handlers здесь ещё не регистрируются: P1.7/P1.8
 * работают на fixture handlers. Finalization binding (P1.8) связывает stage со stage codec его
 * checkpoint/result и stage-specific finalization repository; handler instances и dispatch
 * добавляет WorkRunner следующих пунктов.
 */
#[Singleton]
final class StageRegistry
{
    /** @var array<string, string> stage → handler version */
    private array $versions = [];

    /** @var array<string, StagePayloadCodec> */
    private array $codecs = [];

    /** @var array<string, StageResultRepository> */
    private array $results = [];

    /** @var array<string, StageHandler> */
    private array $handlers = [];

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

    /** Codec checkpoint/result и finalization repository stage; повторная привязка запрещена. */
    public function bindFinalization(StageCode $stage, StagePayloadCodec $codec, StageResultRepository $results): void
    {
        if (! isset($this->versions[$stage->value])) {
            throw new \LogicException('Finalization requires a registered handler version: '.$stage->value);
        }
        if ($codec->stage() !== $stage) {
            throw new \InvalidArgumentException('Stage codec belongs to another stage: '.$stage->value);
        }
        if (isset($this->codecs[$stage->value])) {
            throw new \LogicException('Stage finalization is already bound: '.$stage->value);
        }
        $this->codecs[$stage->value] = $codec;
        $this->results[$stage->value] = $results;
    }

    /**
     * Handler instance исполняющей версии: WorkRunner получает его отсюда, а не выбирает по stage.
     * Привязка требует зарегистрированной версии и повторно не меняется.
     */
    public function bindHandler(StageCode $stage, StageHandler $handler): void
    {
        if (! isset($this->versions[$stage->value])) {
            throw new \LogicException('Handler binding requires a registered handler version: '.$stage->value);
        }
        if (isset($this->handlers[$stage->value])) {
            throw new \LogicException('Stage handler is already bound: '.$stage->value);
        }
        $this->handlers[$stage->value] = $handler;
    }

    /** Отсутствие binding — configuration defect: claimed work без handler не исполняется. */
    public function handler(StageCode $stage): StageHandler
    {
        return $this->handlers[$stage->value] ?? throw new \LogicException('Stage handler is not bound: '.$stage->value);
    }

    public function handlerVersion(StageCode $stage): ?string
    {
        return $this->versions[$stage->value] ?? null;
    }

    /** Отсутствие binding — configuration defect: checkpoint/finalize без него не исполняются. */
    public function resultRepository(StageCode $stage): StageResultRepository
    {
        return $this->results[$stage->value] ?? throw new \LogicException('Stage finalization is not bound: '.$stage->value);
    }

    /** Process codec со всеми привязанными stage codecs (checkpoint envelope и result payload). */
    public function payloadCodec(): ProcessPayloadCodec
    {
        return new ProcessPayloadCodec(array_values($this->codecs));
    }
}
