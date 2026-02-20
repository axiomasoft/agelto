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
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
     * ГАРАНТИЯ НАЛИЧИЯ ФАЙЛА В ОБЛАКЕ
     * Проверяет Media и актуальность в Google File API.
     */
    public function ensureFileInCloud(Media $media): string
    {
        $fileUri = $media->getCustomProperty('google_file_uri');

        if (!$this->isFileActive($fileUri)) {
            // Если в custom_properties пусто или файл протух (48ч) — грузим
            $fileUri = $this->uploadFileAndWait($media->getPath(), $media->file_name);
            $media->setCustomProperty('google_file_uri', $fileUri);
            $media->save();
        }

        return $fileUri;
    }

    /**
     * ПОЛУЧЕНИЕ ИЛИ СОЗДАНИЕ КЭША КОНТЕКСТА
     * Вызываем только тогда, когда нужен детальный анализ (бурение).
     */
    public function getContextCache(Media $media, string $systemInstruction, int $ttlHours = 1): array
    {
        $fileUri = $this->ensureFileInCloud($media);
        $rulesHash = sha1($systemInstruction);

        // Ключ для Redis: привязан к конкретному файлу и версии правил
        $cacheKey = "gemini_context:" . sha1($fileUri . $rulesHash);

        $cachedData = Cache::get($cacheKey);
        if ($cachedData) {
            try {
                // Проверяем, не удален ли кеш на стороне Google
                $this->cachedContents()->retrieve($cachedData['cache_id']);
                return $cachedData;
            } catch (Exception $e) {
                Cache::forget($cacheKey);
            }
        }

        try {
            // Создаем новый кеш
            $cacheResponse = $this->cachedContents()->create(
                model: "models/gemini-1.5-flash",
                systemInstruction: Content::parse($systemInstruction),
                ttl: "{$ttlHours}h",
                displayName: "cache_" . substr($cacheKey, 0, 10),
                contents: [Content::parse(part: "DOC: {$fileUri}")],
            );

            $data = [
                'cache_id'   => $cacheResponse->cachedContent->name,
                'expires_at' => now()->addHours($ttlHours)->toDateTimeString(),
                'rules_hash' => $rulesHash,
            ];

            // Обновляем Media свойствами кеша
            $media->setCustomProperty('cache_file_id', $data['cache_id']);
            $media->setCustomProperty('cache_file_expires', $data['expires_at']);
            $media->save();

            // Кешируем в Redis
            Cache::put($cacheKey, $data, now()->addHours($ttlHours)->subMinutes(5));

            return $data;
        } catch (Exception $e) {
            Log::warning('Gemini Context Cache failed: ' . $e->getMessage());
            return ['cache_id' => null, 'rules_hash' => $rulesHash];
        }
    }

    /**
     * ГЕНЕРАЦИЯ (JSON)
     * Если передан cacheId — используется контекстный кеш (для детального парсинга).
     * Если нет — обычный запрос (для первичного фильтра).
     */
    public function generateJson(string $prompt, string $data, ?string $systemInstruction = null, ?string $cacheId = null): array
    {
        try {
            $model = $this->generativeModel($this->model);
            $config = new GenerationConfig(
                responseMimeType: ResponseMimeType::APPLICATION_JSON,
                temperature: 0.1,
            );

            if ($cacheId) {
                // Экономный запрос через существующий кеш
                $response = $model->withCachedContent($cacheId)
                    ->withGenerationConfig($config)
                    ->generateContent($prompt);
            } else {
                // Обычный запрос (передаем инструкции и URI файла)
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

    public function uploadFileAndWait(string $path, string $displayName, string $mimeType = 'application/pdf'): string
    {
        $mime = GeminiMimeType::tryFrom($mimeType);
        if ($mime === null) throw new InvalidArgumentException("Unsupported MIME type: {$mimeType}");

        $fileResponse = $this->files()->upload(filename: $path, mimeType: $mime, displayName: $displayName);

        $start = now();
        while (true) {
            $currentFile = $this->files()->metadataGet($fileResponse->name);
            $state = is_string($currentFile->state) ? $currentFile->state : $currentFile->state->value;

            if ($state === 'ACTIVE') return $currentFile->uri;
            if ($state === 'FAILED') throw new Exception("Gemini File API failed: {$displayName}");
            if (now()->diffInMinutes($start) > 5) throw new Exception("Timeout waiting for file: {$displayName}");

            sleep(3);
        }
    }

    public function isFileActive(?string $fileUri): bool
    {
        if (!$fileUri) return false;
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
