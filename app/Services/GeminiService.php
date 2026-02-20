<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use Gemini;
use Gemini\Client;
use Gemini\Data\Content;
use Gemini\Data\GenerationConfig;
use Gemini\Enums\MimeType as GeminiMimeType;
use Gemini\Enums\ResponseMimeType;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * @mixin Client
 */
final class GeminiService
{
    private Client $nativeClient;

    private string $model = 'gemini-2.0-flash';

    public function __construct()
    {
        $this->buildClient();
    }

    /**
     * МАГИЯ: Делегирование всех стандартных методов (chat, files, embed и т.д.) нативному клиенту
     */
    public function __call(string $name, array $arguments)
    {
        return $this->nativeClient->$name(...$arguments);
    }

    /**
     * Загрузка файла и ожидание его готовности (ACTIVE)
     * Возвращает URI для использования в промпте
     */
    public function uploadFileAndWait(string $path, string $displayName, string $mimeType = 'application/pdf'): string
    {
        // 1. Загрузка через нативный клиент (прокси подхватится автоматически)
        $mime = GeminiMimeType::tryFrom($mimeType);
        if ($mime === null) {
            throw new InvalidArgumentException("Unsupported MIME type for Gemini upload: {$mimeType}");
        }
        $fileResponse = $this->nativeClient->files()->upload(
            filename: $path,
            mimeType: $mime,
            displayName: $displayName,
        );

        $start = now();

        // 2. Цикл ожидания готовности
        while (true) {
            $currentFile = $this->nativeClient->files()->metadataGet($fileResponse->name);

            // В библиотеке состояние может быть в ->state или ->state->value
            $state = is_string($currentFile->state) ? $currentFile->state : $currentFile->state->value;

            if ($state === 'ACTIVE') {
                return $currentFile->uri;
            }

            if ($state === 'FAILED') {
                throw new Exception("Gemini File API failed to process file: {$displayName}");
            }

            if (now()->diffInMinutes($start) > 5) {
                throw new Exception("Timeout waiting for Gemini File API: {$displayName}");
            }

            sleep(3);
        }
    }

    /**
     * Твой кастомный метод для смет
     */
    public function generateJson(string $prompt, string $data, ?string $systemInstruction = null): array
    {
        try {
            $model = $this->nativeClient->generativeModel($this->model);

            if ($systemInstruction) {
                $model = $model->withSystemInstruction(Content::parse($systemInstruction));
            }

            $config = new GenerationConfig(
                responseMimeType: ResponseMimeType::APPLICATION_JSON,
                temperature: 0.1,
            );

            // Если $data — это URI файла (https://generativelanguage...), Gemini поймет это
            $response = $model->withGenerationConfig($config)->generateContent([
                "PROMPT:\n{$prompt}",
                "DATA:\n{$data}",
            ]);

            return json_decode($response->text(), true) ?? [];
        } catch (Exception $e) {
            Log::error('Gemini Extended Client Error: '.$e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function setModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    private function buildClient(): void
    {
        $httpClient = new GuzzleClient([
            'proxy' => config('app.proxy.base'),
            'verify' => false,
            'timeout' => 120, // Увеличили таймаут для тяжелых файлов
        ]);

        $this->nativeClient = Gemini::factory()
            ->withApiKey(config('gemini.api_key'))
            ->withHttpClient($httpClient)
            ->make();
    }
}
