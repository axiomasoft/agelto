<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis\Estimates;

use App\Services\GeminiService;
use Platform\Dto\PromtRule\Group;
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
        $group = Group::PrimaryAnalysis;

        $media = $documentAnalysis->getDocumentForAnalysis();

        // Проверяем, что это PDF (Gemini File API лучше всего работает с ними)
        if (! $media || $media->extension !== 'pdf') {
            return null;
        }

        /**
         * 1. ГАРАНТИРУЕМ НАЛИЧИЕ В ОБЛАКЕ
         * Даже если файл 200 МБ, мы его грузим в File API.
         * Метод ensureFileInCloud сам проверит custom_properties и не будет
         * перезаливать файл, если он там уже есть и активен.
         */
        $fileUri = $this->gemini->ensureFileInCloud($media);

        // 2. ПОЛУЧАЕМ ИНСТРУКЦИИ ДЛЯ ФИЛЬТРАЦИИ
        $systemInstructions = $this->promptRepo->getSystemInstructionsForGroup($group);

        /**
         * 3. ЗАПРОС БЕЗ КЭША
         * Дима, здесь мы передаем $fileUri напрямую.
         * Gemini 2.0 Flash отлично справляется с длинным контекстом.
         * Кэширование (cacheId) оставляем null, чтобы не плодить платные сущности
         * для первичного отсева мусора.
         */
        $response = $this->gemini->generateJson(
            prompt: 'Выполни первичный анализ документа согласно инструкциям.',
            data: $fileUri,
            systemInstruction: $systemInstructions,
            cacheId: null
        );

        // 4. СОХРАНЯЕМ ОТВЕТ
        return $documentAnalysis->responses()->create([
            'step' => 'primary_analysis',
            'cache_file_id' => null, // Явно фиксируем, что кэш не использовался
            'data' => $response['data'],
            'success' => $response['success'],
            'message' => $response['message'],
            'promt' => $response['promt'],
            'step' => $group,
        ]);
    }
}
