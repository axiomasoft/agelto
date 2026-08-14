<?php

declare(strict_types=1);

namespace App\Services\Procurement\Fixture;

use App\Enums\Procurement\Ai\AnalysisStep;
use App\Exceptions\Procurement\Ai\FixtureCaseNotFound;
use App\Exceptions\Procurement\Ai\GoldFileUnreadable;
use App\Models\Procurement\Ai\Analysis;
use App\Repositories\Procurement\Ai\AnalysisReadRepository;
use App\Repositories\Procurement\Ai\FixtureStoreRepository;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use JsonException;

/**
 * ОДИН владелец резолва «код эталона ↔ активный `Analysis(step=extraction)`» + загрузки gold-файла
 * (§4/§5 `root/service-layer-map.md`) — устраняет 39-строчный побайтовый дубль между
 * `estimate:eval:diff` и `estimate:prompts:suggest` (findings §2). БРОСАЕТ ВСЕГДА (§1 инвариант 4): разная
 * реакция двух вызывающих на один отказ достигается на стороне вызывающего, не двумя формами.
 */
final readonly class FixtureCaseResolver
{
    public function __construct(
        private FixtureStoreRepository $fixtures,
        private DocumentStoreRepository $documents,
        private AnalysisReadRepository $analyses,
    ) {}

    /**
     * @return array{0: string, 1: Analysis, 2: array<string, mixed>}
     *
     * @throws FixtureCaseNotFound|GoldFileUnreadable
     */
    public function resolveByCode(string $code): array
    {
        $analysis = $this->latestExtractionAnalysis($code);

        if ($analysis === null) {
            throw new FixtureCaseNotFound("Активный Analysis(step=extraction) для эталона {$code} не найден — прогони estimate:eval:run.");
        }

        return [$code, $analysis, $this->loadGold($code)];
    }

    /**
     * @return array{0: string, 1: Analysis, 2: array<string, mixed>}
     *
     * @throws FixtureCaseNotFound|GoldFileUnreadable
     */
    public function resolveByAnalysisId(int $analysisId): array
    {
        $analysis = $this->analyses->findByIdAndStep($analysisId, AnalysisStep::Extraction);

        if ($analysis === null) {
            throw new FixtureCaseNotFound("Analysis #{$analysisId} (step=extraction) не найден.");
        }

        $code = $this->fixtureCodeFor($analysis);

        if ($code === null) {
            throw new FixtureCaseNotFound("Analysis #{$analysis->id} не связан с эталоном (external_id документа не по конвенции fixture:<CODE>).");
        }

        return [$code, $analysis, $this->loadGold($code)];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws GoldFileUnreadable
     */
    private function loadGold(string $code): array
    {
        // `ai.fixtures.gold_dir` уже несёт `base_path()` (config/ai.php) — повторный base_path()
        // задвоил бы путь.
        $goldDirectory = config('ai.fixtures.gold_dir');

        if (! is_string($goldDirectory)) {
            throw new GoldFileUnreadable("Gold-директория для {$code} не настроена.");
        }

        $goldPath = "{$goldDirectory}/{$code}.gold.json";

        if (! is_file($goldPath)) {
            throw new GoldFileUnreadable("Gold-файл не найден: {$goldPath}");
        }

        try {
            $contents = file_get_contents($goldPath);

            if ($contents === false) {
                throw new GoldFileUnreadable("Gold-файл недоступен для чтения: {$goldPath}");
            }

            $decoded = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                throw new GoldFileUnreadable("Gold-файл не содержит JSON-объект: {$goldPath}");
            }

            return $this->stringKeyedArray($decoded);
        } catch (JsonException $e) {
            throw new GoldFileUnreadable("Gold-файл не распарсен: {$e->getMessage()}");
        }
    }

    private function latestExtractionAnalysis(string $code): ?Analysis
    {
        $fixture = $this->fixtures->findByCode($code);

        if ($fixture === null) {
            return null;
        }

        $document = $fixture->document;

        if ($document === null) {
            return null;
        }

        $version = $this->documents->findVersionByHash($document->id, $fixture->sha256);

        if ($version === null) {
            return null;
        }

        return $this->analyses->activeForVersion($version->id, AnalysisStep::Extraction);
    }

    private function fixtureCodeFor(Analysis $analysis): ?string
    {
        $externalId = $analysis->documentVersion?->document?->external_id;

        if ($externalId === null || ! str_starts_with($externalId, 'fixture:')) {
            return null;
        }

        $code = substr($externalId, strlen('fixture:'));

        return $this->fixtures->findByCode($code) !== null ? $code : null;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
