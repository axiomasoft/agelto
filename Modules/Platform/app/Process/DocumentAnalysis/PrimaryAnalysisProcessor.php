<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis\Estimates;

use App\Services\GeminiService;
use Platform\Models\DocumentAnalysis\DocumentAnalysis;
use Platform\Models\DocumentAnalysis\DocumentAnalysisResponse;
use Platform\Repositories\Promt\PromptRuleRepository;
use Platform\Dto\PromtRule\Group;

class PrimaryAnalysisProcessor
{
    public function __construct(
        protected GeminiService $gemini,
        protected PromptRuleRepository $promptRepo
    ) {}

    public function analysis(DocumentAnalysis $documentAnalysis): ?DocumentAnalysisResponse
    {
        $media = $documentAnalysis->getDocumentForAnalysis();

        if (!$media || $media->extension !== 'pdf') {
            return null;
        }

        // 1. Загружаем и ждем URI прямо через GeminiService
        $fileUri = $this->gemini->uploadFileAndWait(
            $media->getPath(),
            $media->file_name
        );

        // 2. Получаем инструкции
        $systemInstructions = $this->promptRepo->getSystemInstructionsForGroup(Group::PrimaryAnalysis);

        // 3. Запрос
        $response = $this->gemini->generateJson(
            prompt: "Проанализируй смету. Если на первых 3 стр пусто — success:false.",
            data: $fileUri, // Здесь теперь ссылка на файл в Google
            systemInstruction: $systemInstructions
        );


        $documentAnalysisResponse = $documentAnalysis->responses()->create([
            'step' => 'primary_analysis',
            'payload' => $response['data'] ?? [],
            'raw_response' => $response
        ]);

        return $documentAnalysisResponse;
    }
}
