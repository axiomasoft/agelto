<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis\Estimates;

use App\Services\GeminiService;
use Platform\Dto\PromtRule\Step;
use Platform\Models\DocumentAnalysis\DocumentAnalysis;
use Platform\Models\DocumentAnalysis\DocumentAnalysisResponse;
use Platform\Repositories\Promt\PromptRuleRepository;

final class PrimaryAnalysisProcessor
{
    public function __construct(
        private GeminiService $gemini,
        private PromptRuleRepository $promptRepo
    ) {}

    public function run(DocumentAnalysis $documentAnalysis): ?DocumentAnalysisResponse
    {
        $step = Step::PrimaryAnalysis;

        $media = $documentAnalysis->getDocumentForAnalysis();
        if (! $media || $media->extension !== 'pdf') {
            return null;
        }

        // 1. Проверяем файл (и отпечаток)
        $fileUri = $this->gemini->ensureFileInCloud($documentAnalysis);

        // 2. Инструкции
        $systemInstructions = $this->promptRepo->getSystemInstructionsForStep(Step::PrimaryAnalysis);

        // 3. Запрос БЕЗ кэша (cacheId: null)
        $response = $this->gemini->generateJson(
            prompt: 'Выполни первичный анализ согласно правилам.',
            data: $fileUri,
            systemInstruction: $systemInstructions,
            cacheId: null
        );

        return $documentAnalysis->responses()->create([
            'step' => $step,
            'success' => $response['success'] ?? true, // У тебя в миграции есть это поле
            'message' => $response['message'] ?? null, // И это

            // В миграции поле называется 'data'
            'data' => $response['data'] ?? [],

            // В миграции поле 'promt' (через t) — сохраняем туда инструкции
            'promt' => $systemInstructions,

            // Поля связи с кэшем (добавленные нами)
            'cache_file_id' => null,
            'rules_hash' => sha1($systemInstructions),
        ]);
    }
}
