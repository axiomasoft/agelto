<?php

declare(strict_types=1);

namespace App\Dto\Llm\Cache;

use App\Dto\Llm\Requests\PromptSnapshot;
use App\Services\Llm\Schema\CanonicalJson;

/** The complete immutable prefix; task instructions remain a separate user part. */
final readonly class CachePrefix
{
    /** @var non-empty-list<ProviderFileAsset> */
    public array $files;

    /** @param array<array-key, ProviderFileAsset> $files */
    public function __construct(public ContextCacheKey $key, public PromptSnapshot $system, array $files)
    {
        if ($files === [] || ! array_is_list($files)) {
            throw new \InvalidArgumentException('Cache prefix requires ordered files.');
        }
        if ($key->systemHash !== $system->hash
            || $key->contentOrderingHash !== self::orderingHash($files)) {
            throw new \InvalidArgumentException('Cache prefix identity drift.');
        }
        foreach ($files as $file) {
            $file->key->assertContext($key->context);
        }
        $this->files = $files;
    }

    /** @param list<ProviderFileAsset> $files */
    public static function orderingHash(array $files): string
    {
        return hash('sha256', CanonicalJson::encode(array_map(static fn (ProviderFileAsset $file): array => [$file->key->contentHash, $file->key->mime], $files)));
    }
}
