<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

/**
 * Sanitized evidence неожиданного programming Throwable handler:
 * signature = sha256(class + file + line) без message, payload и secrets. Executing handler version
 * берётся из attempt под work lock, а не от вызывающего.
 */
final readonly class HandlerFaultEvidence
{
    public function __construct(public ContentHash $signature, public string $exceptionClass)
    {
        ProcessValue::text($exceptionClass);
    }

    public static function fromThrowable(\Throwable $fault): self
    {
        $class = $fault::class;

        return new self(ContentHash::of('handler-fault:v1:'.$class.'@'.basename($fault->getFile()).':'.$fault->getLine()), $class);
    }
}
