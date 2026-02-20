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
use Illuminate\Support\Facades\Cache;
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

    public function __call(string $name, array $arguments)
    {
        return $this->nativeClient->$name(...$arguments);
    }

    /**
     * Загрузка файла и ожидание его готовности (ACTIVE)
     */
    public function uploadFileAndWait(string $path, string $displayName, string $mimeType = 'application/pdf'): string
    {
        $mime = GeminiMimeType::tryFrom($mimeType);
        if ($mime === null) {
            throw new InvalidArgumentException("Unsupported MIME type: {$mimeType}");
        }

        $fileResponse = $this->files()->upload(
            filename: $path,
            mimeType: $mime,
            displayName: $displayName,
        );

        $start = now();
        while (true) {
            $currentFile = $this->files()->metadataGet($fileResponse->name);
            $state = is_string($currentFile->state) ? $currentFile->state : $currentFile->state->value;

            if ($state === 'ACTIVE') {
                return $currentFile->uri;
            }

            if ($state === 'FAILED') {
                throw new Exception("Gemini File API failed: {$displayName}");
            }

            if (now()->diffInMinutes($start) > 5) {
                throw new Exception("Timeout waiting for file: {$displayName}");
            }

            sleep(3);
        }
    }

    /**
     * Проверка: существует ли файл в Google (они живут 48 часов)
     */
    public function isFileActive(?string $fileUri): bool
    {
        if (! $fileUri) {
            return false;
        }
        try {
            $parts = explode('/', $fileUri);
            $fileName = 'files/' . end($parts);
            $file = $this->files()->metadataGet($fileName);
            $state = is_string($file->state) ? $file->state : $file->state->value;

            return $state === 'ACTIVE';
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * ПОЛУЧЕНИЕ ИЛИ СОЗДАНИЕ КЭША (Для детального парсинга)
     * Связываем инструкции и файл в один контекст.
     */
    public function getContextCache(string $fileUri, string $systemInstruction, int $ttlHours = 1): array
    {
        // Хеш от правил + URI файла
        $hash = sha1($fileUri . $systemInstruction);
        $cacheKey = "gemini_cache:{$hash}";

        // 1. Проверяем Redis
        $cachedData = Cache::get($cacheKey);
        if ($cachedData) {
            try {
                $this->cachedContents()->retrieve($cachedData['cache_id']);

                return $cachedData;
            } catch (Exception $e) {
                Cache::forget($cacheKey);
            }
        }

        // 2. Создаем кэш в Google (обязательно передаем и инструкции, и файл)
        try {
            $cacheResponse = $this->cachedContents()->create(
                model: "models/{$this->model}",
                systemInstruction: Content::parse($systemInstruction),
                tools: [],
                toolConfig: null,
                ttl: "{$ttlHours}h",
                displayName: "cache_{$hash}",
                contents: [
                    Content::parse(part: "DOC_URI: {$fileUri}"),
                ],
            );

            $data = [
                'cache_id' => $cacheResponse->cachedContent->name,
                'expires_at' => now()->addHours($ttlHours)->toDateTimeString(),
            ];

            Cache::put($cacheKey, $data, now()->addHours($ttlHours)->subMinutes(5));

            return $data;
        } catch (Exception $e) {
            Log::warning('Context Cache не создан: ' . $e->getMessage());

            return ['cache_id' => null, 'expires_at' => null];
        }
    }

    /**
     * УМНАЯ ГЕНЕРАЦИЯ (JSON)
     * Если передан $cacheId — используем кэш, если нет — обычный запрос.
     */
    public function generateJson(string $prompt, string $data, ?string $systemInstruction = null, ?string $cacheId = null): array
    {
        try {
            $model = $this->nativeClient->generativeModel($this->model);
            $config = new GenerationConfig(
                responseMimeType: ResponseMimeType::APPLICATION_JSON,
                temperature: 0.1,
            );

            if ($cacheId) {
                // Работа через кэш (системные правила и файл уже внутри)
                $response = $model->withCachedContent($cacheId)
                    ->withGenerationConfig($config)
                    ->generateContent($prompt);
            } else {
                // Обычный запрос (как было раньше)
                if ($systemInstruction) {
                    $model = $model->withSystemInstruction(Content::parse($systemInstruction));
                }
                $response = $model->withGenerationConfig($config)->generateContent([
                    "PROMPT:\n{$prompt}",
                    "DATA:\n{$data}",
                ]);
            }

            return json_decode($response->text(), true) ?? [];
        } catch (Exception $e) {
            Log::error('Gemini Service Error: ' . $e->getMessage());

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
            'proxy' => config('sys.proxies.gemini_proxy'),
            'verify' => false,
            'timeout' => 300,
        ]);

        $this->nativeClient = Gemini::factory()
            ->withApiKey(config('gemini.api_key'))
            ->withHttpClient($httpClient)
            ->make();
    }
}
