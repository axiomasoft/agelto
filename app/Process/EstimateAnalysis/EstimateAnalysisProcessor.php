<?php

declare(strict_types=1);

namespace App\Process\EstimateAnalysis;

use App\Services\GeminiService;
use App\Dto\Prompt\Step;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Models\DocumentAnalysis\DocumentAnalysisResponse;
use App\Repositories\Prompt\PromptRuleRepository;

final class EstimateAnalysisProcessor
{
    public function __construct(
        private GeminiService $gemini,
        private PromptRuleRepository $promptRepo
    ) {}

    public function run(DocumentAnalysis $documentAnalysis): ?DocumentAnalysisResponse
    {
        $systemInstructions = $this->promptRepo->getSystemInstructionsForStep(Step::DetailedParsing);

        // 1. Получаем ID кэша (создастся или возьмется готовый)
        $cacheId = $this->gemini->getContextCache($documentAnalysis, $systemInstructions);

        // 2. Цикл запросов (пагинация и т.д.)
        // ... твой код запросов ...
        $response = $this->gemini->generateJson(
            prompt: 'Извлеки данные сметы...',
            data: '',
            cacheId: $cacheId
        );

        $res = $documentAnalysis->responses()->create([
            'step' => 'detailed_parsing',
            'cache_file_id' => $cacheId,
            'rules_hash' => sha1($systemInstructions),
            'payload' => $response['data'] ?? [],
        ]);

        // 3. ЕСЛИ ЭТО БЫЛ ПОСЛЕДНИЙ ШАГ ПАРСИНГА — УДАЛЯЕМ КЭШ
        /* if ($this->isParsingFinished($documentAnalysis)) {
            $this->gemini->deleteCache($documentAnalysis);
        } */

        return $res;
    }
}
