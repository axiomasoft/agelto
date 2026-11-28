<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Exceptions\Llm\OperationDenied;
use App\Services\Llm\Contracts\ProviderWireCodec;
use App\Services\Llm\Providers\GeminiWireCodec;
use App\Services\Llm\Providers\OpenAiChatCompletionsClient;
use Illuminate\Container\Attributes\Singleton;

/** Fail closed until the owning provider package explicitly registers its wire codec. */
#[Singleton]
final class ProviderCodecs
{
    /** @var array<string, ProviderWireCodec> */
    private array $codecs = [];

    public static function default(): self
    {
        $registry = new self;
        $registry->register('gemini', new GeminiWireCodec);
        $registry->register('openai', new OpenAiChatCompletionsClient);
        $registry->register('openai_compatible', new OpenAiChatCompletionsClient);

        return $registry;
    }

    public function register(string $provider, ProviderWireCodec $codec): void
    {
        $this->codecs[$provider] = $codec;
    }

    public function has(string $provider): bool
    {
        return isset($this->codecs[$provider]);
    }

    public function for(string $provider): ProviderWireCodec
    {
        return $this->codecs[$provider] ?? throw new OperationDenied('provider_codec_unregistered');
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->codecs);
    }
}
