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
        $systemInstructions = $this->promptRepo->getSystemInstructionsForStep(Step::EstimateFetchItems);

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
            'step' => Step::EstimateFetchItems->value,
            'cache_file_id' => $cacheId,
            'rules_hash' => sha1($systemInstructions),
            'payload' => $response['data'] ?? [],
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
