<?php

declare(strict_types=1);

namespace App\Process\EstimateAnalysis;

use App\Dto\Prompt\Step;
use App\Enums\AnalysisStatus;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Models\DocumentAnalysis\DocumentAnalysisResponse;
use App\Repositories\Prompt\PromptRuleRepository;
use App\Services\GeminiService;

final class EstimateAnalysisProcessor
{
    public function __construct(
        private GeminiService $gemini,
        private PromptRuleRepository $promptRepo
    ) {}

    public function run(DocumentAnalysis $documentAnalysis): ?DocumentAnalysisResponse
    {
        $systemInstructions = $this->promptRepo->systemInstructionsFor(Step::EstimateFetchItems);

        // 1. Получаем ID кэша (создастся или возьмется готовый)
        $cacheId = $this->gemini->getContextCache($documentAnalysis, $systemInstructions);

        // 2. Цикл запросов (пагинация и т.д.)
        // ... твой код запросов ...
        $response = $this->gemini->generateJson(
            prompt: 'Извлеки данные сметы...',
            data: '',
            cacheId: $cacheId
        );

        $usage = $this->gemini->lastUsage();

        $res = $documentAnalysis->responses()->create([
            'step' => Step::EstimateFetchItems->value,
            'cache_file_id' => $cacheId,
            'rules_hash' => sha1($systemInstructions),
            'data' => $response['data'] ?? [],
            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
            'completion_tokens' => $usage['completion_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
        ]);

        // Финализируем стадию: иначе документ навсегда остаётся в EstimateAnalyzing
        // и guard идемпотентности в job не срабатывает.
        $documentAnalysis->transitionStatus(
            status: AnalysisStatus::EstimateAnalyzed,
            message: 'Анализ сметы завершён',
        );

        return $res;
    }
}
