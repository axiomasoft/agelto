<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Llm\Requests\PromptSnapshot;
use App\Dto\Procurement\Process\ContentHash;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Procurement\Process\PayloadReader;

final readonly class AnalysisRecipe
{
    public string $json;

    public ContentHash $hash;

    public function __construct(
        public ContentHash $preparationHash,
        public string $analyzerVersion,
        public PromptSnapshot $prompt,
        public ContentHash $schemaHash,
        public PinnedLlmContext $llm,
        public CoverageStrategy $coverage,
        public string $currency,
        public string $locale,
    ) {
        if ($analyzerVersion === '' || preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || $locale === '') {
            throw new \InvalidArgumentException('Incomplete semantic recipe.');
        }
        $this->json = CanonicalJson::encode(['version' => 1, 'preparation_hash' => $preparationHash->hex,
            'analyzer_version' => $analyzerVersion, 'prompt_content' => $prompt->content, 'prompt_version' => $prompt->version,
            'prompt_hash' => $prompt->hash, 'schema_hash' => $schemaHash->hex, 'llm' => json_decode($llm->json, flags: JSON_THROW_ON_ERROR),
            'coverage' => $coverage->fields(), 'currency' => $currency, 'locale' => $locale]);
        $this->hash = ContentHash::of($this->json);
    }

    public static function restore(string $json, int $version): self
    {
        $reader = PayloadReader::json($json);
        if ($version !== 1 || $reader->integer('version') !== 1) {
            throw new \InvalidArgumentException('Unknown recipe codec; create a new analysis.');
        }
        $coverage = $reader->object('coverage');
        $degraded = $coverage->object->allowDegradedRepresentation ?? null;
        if (! is_bool($degraded)) {
            throw new \InvalidArgumentException('Invalid degraded representation policy.');
        }
        $recipe = new self(new ContentHash($reader->string('preparation_hash')), $reader->string('analyzer_version'),
            new PromptSnapshot($reader->string('prompt_content'), $reader->integer('prompt_version')), new ContentHash($reader->string('schema_hash')),
            PinnedLlmContext::restore(CanonicalJson::encode($reader->object('llm')->object)),
            new CoverageStrategy($coverage->integer('windowSpan'), $coverage->integer('overlap'), $coverage->integer('minSpan'),
                $coverage->integer('maxDepth'), $coverage->integer('maxSplits'), $degraded), $reader->string('currency'), $reader->string('locale'));
        if ($recipe->json !== CanonicalJson::encode($reader->object)) {
            throw new \InvalidArgumentException('Recipe snapshot drift or unrecognized fields.');
        }

        return $recipe;
    }

    public function assertSame(self $candidate): void
    {
        if ($this->hash != $candidate->hash) {
            throw new \InvalidArgumentException('Recipe drift requires a new analysis run.');
        }
    }
}
