<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Llm\Requests\TaskManifest;
use App\Dto\Procurement\Process\ContentHash;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Procurement\Process\PayloadReader;

final readonly class AnalysisRecipe
{
    public const int CODEC_VERSION = 2;

    public string $json;

    public ContentHash $hash;

    public function __construct(
        public ContentHash $preparationHash,
        public string $analyzerVersion,
        public TaskManifest $tasks,
        public PinnedLlmContext $llm,
        public CoverageStrategy $coverage,
        public string $currency,
        public string $locale,
        public ScreeningPolicySnapshot $screening = new ScreeningPolicySnapshot,
    ) {
        if ($analyzerVersion === '' || preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || $locale === '') {
            throw new \InvalidArgumentException('Incomplete semantic recipe.');
        }
        $this->json = CanonicalJson::encode(['version' => self::CODEC_VERSION, 'preparation_hash' => $preparationHash->hex,
            'analyzer_version' => $analyzerVersion, 'tasks' => json_decode($tasks->json, flags: JSON_THROW_ON_ERROR), 'llm' => json_decode($llm->json, flags: JSON_THROW_ON_ERROR),
            'coverage' => $coverage->fields(), 'currency' => $currency, 'locale' => $locale, 'screening' => $screening->fields()]);
        $this->hash = ContentHash::of($this->json);
    }

    public static function restore(string $json, int $version): self
    {
        $reader = PayloadReader::json($json);
        if ($version !== self::CODEC_VERSION || $reader->integer('version') !== self::CODEC_VERSION) {
            throw new \InvalidArgumentException('Unknown recipe codec; create a new analysis.');
        }
        $coverage = $reader->object('coverage');
        $degraded = $coverage->object->allowDegradedRepresentation ?? null;
        if (! is_bool($degraded)) {
            throw new \InvalidArgumentException('Invalid degraded representation policy.');
        }
        $screening = $reader->object('screening')->object;
        if ((! is_int($screening->confidence_low ?? null) && ! is_float($screening->confidence_low ?? null))
            || (! is_int($screening->confidence_high ?? null) && ! is_float($screening->confidence_high ?? null))
            || ! is_bool($screening->ignored_by_rule ?? null)) {
            throw new \InvalidArgumentException('Invalid screening policy snapshot.');
        }
        $recipe = new self(new ContentHash($reader->string('preparation_hash')), $reader->string('analyzer_version'),
            TaskManifest::restore(CanonicalJson::encode($reader->object('tasks')->object)),
            PinnedLlmContext::restore(CanonicalJson::encode($reader->object('llm')->object)),
            new CoverageStrategy($coverage->integer('windowSpan'), $coverage->integer('overlap'), $coverage->integer('minSpan'),
                $coverage->integer('maxDepth'), $coverage->integer('maxSplits'), $degraded), $reader->string('currency'), $reader->string('locale'),
            new ScreeningPolicySnapshot($screening->confidence_low, $screening->confidence_high, $screening->ignored_by_rule));
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
