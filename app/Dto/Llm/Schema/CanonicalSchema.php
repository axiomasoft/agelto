<?php

declare(strict_types=1);

namespace App\Dto\Llm\Schema;

use App\Services\Llm\Schema\CanonicalJson;
use stdClass;

final readonly class CanonicalSchema
{
    public string $json;

    public string $hash;

    /** @param array<string, mixed> $definition */
    public function __construct(array $definition)
    {
        if (isset($definition['$schema']) && $definition['$schema'] !== 'https://json-schema.org/draft/2020-12/schema') {
            throw new \InvalidArgumentException('Only JSON Schema draft 2020-12 is canonical.');
        }
        $this->json = CanonicalJson::encode([...$definition, '$schema' => 'https://json-schema.org/draft/2020-12/schema']);
        if (strlen($this->json) > 262144) {
            throw new \InvalidArgumentException('Canonical schema size limit.');
        }
        $this->hash = hash('sha256', $this->json);
    }

    public function object(): stdClass
    {
        $schema = json_decode($this->json, flags: JSON_THROW_ON_ERROR);
        if (! $schema instanceof stdClass) {
            throw new \LogicException('Canonical schema must be an object.');
        }

        return $schema;
    }
}
