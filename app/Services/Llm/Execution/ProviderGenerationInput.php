<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Dto\Llm\Cache\CachePrefix;
use App\Dto\Llm\Cache\ContextCacheAsset;
use App\Dto\Llm\Cache\ProviderCapabilities;
use App\Dto\Llm\Cache\ProviderFileAsset;
use App\Dto\Llm\Requests\PromptSnapshot;
use App\Dto\Llm\Schema\ProviderSchema;

/** Immutable generation input. Creating files/cache never happens during compilation. */
final readonly class ProviderGenerationInput
{
    /** @var list<ProviderFileAsset> */
    public array $files;

    /** @param array<array-key, ProviderFileAsset> $files */
    public function __construct(public PromptSnapshot $system, public string $user, public ProviderSchema $schema,
        array $files = [], public ?ContextCacheAsset $cache = null, public ?CachePrefix $prefix = null,
        public ?ProviderCapabilities $capabilities = null)
    {
        if ($user === '' || strlen($user) > 8_388_608) {
            throw new \InvalidArgumentException('Invalid bounded generation input.');
        }
        if (! array_is_list($files) || ($cache === null) !== ($prefix === null)
            || ($cache !== null && ($prefix === null || $cache->key != $prefix->key || $prefix->system != $system || $prefix->files != $files))) {
            throw new \InvalidArgumentException('Generation prefix identity drift.');
        }
        $this->files = $files;
    }
}
