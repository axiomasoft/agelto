<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis;

use App\Services\GeminiService;
use Platform\Dto\PromtRule\Step;
use Platform\Models\DocumentAnalysis\DocumentAnalysis;
use Platform\Models\DocumentAnalysis\DocumentAnalysisResponse;
use Platform\Repositories\Promt\PromptRuleRepository;
use Platform\Process\DocumentAnalysis\Schema\Responses\PrimaryAnalysisResponse;
use Platform\Process\DocumentPrepare\DocumentProcessor;
use App\Services\AppLogger;
use App\Enums\LogOutput;

final class PrimaryAnalysisProcessor
{
    public function __construct(
        private GeminiService $gemini,
        private PromptRuleRepository $promptRepo,
        private DocumentProcessor $documentProcessor,
        protected readonly AppLogger $logger,
    ) {}

    public function run(DocumentAnalysis $documentAnalysis, bool $forcePrepare = false): ?DocumentAnalysisResponse
    {
        $this->logger->info('Начало первичного анализа ' . $documentAnalysis->id, LogOutput::Console);

        $media = $documentAnalysis->getDocumentForAnalysis();

        // Подготовка документа
        if (!$media && $forcePrepare) {
            $this->documentProcessor->fromModel($documentAnalysis);
        }

        $step = Step::PrimaryAnalysis;

        $fileUri = $this->gemini->ensureFileInCloud($documentAnalysis);
        $systemInstructions = $this->promptRepo->getSystemInstructionsForStep($step);

        $response = $this->gemini->generateJson(
            prompt: "Выполни первичный анализ согласно правилам.",
            data: $fileUri,
            systemInstruction: $systemInstructions
        );

        // 1. Оборачиваем в схему и валидируем
        // Если используешь Spatie Laravel Data:
        $responseObject = PrimaryAnalysisResponse::from($response);

        // 2. Дополнительная проверка бизнес-логики (если нужна)
        if (!$responseObject->success && empty($responseObject->found_documents)) {
            // обработка логики "пустого" результата
        }

        // 3. Сохраняем в базу, используя свойства объекта
        return $documentAnalysis->responses()->create([
            'step' => $step,
            'success' => $responseObject->success,
            'message' => $responseObject->message ?? null,
            'data' => $responseObject->data->toArray(),
            'promt' => $systemInstructions,
            'rules_hash' => sha1($systemInstructions),
        ]);
    }
}
