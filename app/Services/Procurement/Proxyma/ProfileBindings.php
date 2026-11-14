<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma;

use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Process\ProcessUuid;

/** Ссылка consumer snapshot на перенесённый профиль; secrets и selection здесь отсутствуют. */
final readonly class ProfileBindings
{
    /** @var array<string, HttpRouteBinding> */
    public array $bindings;

    /** @param list<HttpRouteBinding> $bindings */
    public function __construct(array $bindings, public ?ProcessUuid $profileId = null, public bool $random = false)
    {
        $indexed = [];
        foreach ($bindings as $binding) {
            if (isset($indexed[$binding->id->value])) {
                throw new \InvalidArgumentException('Duplicate consumer route reference.');
            }
            $indexed[$binding->id->value] = $binding;
        }
        $this->bindings = $indexed;
    }
}
