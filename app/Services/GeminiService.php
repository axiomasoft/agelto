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
use Platform\Models\DocumentAnalysis\DocumentAnalysis;
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
    public function ensureFileInCloud(DocumentAnalysis $analysis): string
    {
        $media = $analysis->getDocumentForAnalysis();
        $currentFingerprint = $this->getFileFingerprint($media);

        // Если файл изменился — сбрасываем старый кэш в анализе
        if ($analysis->file_fingerprint !== $currentFingerprint) {
            $analysis->update([
                'file_fingerprint' => $currentFingerprint,
                'gemini_cache_id' => null,
                'gemini_cache_expires_at' => null
            ]);
        }

        $fileUri = $media->getCustomProperty('google_file_uri');

        if (!$this->isFileActive($fileUri)) {
            $fileUri = $this->uploadFileAndWait($media->getPath(), $media->file_name);
            $media->setCustomProperty('google_file_uri', $fileUri);
            $media->save();
        }

        return $fileUri;
    }

    private function getFileFingerprint(\Spatie\MediaLibrary\MediaCollections\Models\Media $media): string
    {
        $path = $media->getPath();
        return md5($media->file_name . filesize($path) . filemtime($path));
    }

    /**
     * Ручное удаление кэша (вызываем в конце Detailed Analysis)
     */
    public function deleteCache(DocumentAnalysis $analysis): void
    {
        if ($analysis->gemini_cache_id) {
            try {
                $this->cachedContents()->delete($analysis->gemini_cache_id);
            } catch (\Exception $e) {
            }

            $analysis->update([
                'gemini_cache_id' => null,
                'gemini_cache_expires_at' => null
            ]);
        }
    }

    /**
     * ПОЛУЧЕНИЕ ИЛИ СОЗДАНИЕ КЭША КОНТЕКСТА
     * Вызываем только тогда, когда нужен детальный анализ (бурение).
     */
    public function getContextCache(DocumentAnalysis $analysis, string $systemInstruction, int $ttlHours = 6): string
    {
        $fileUri = $this->ensureFileInCloud($analysis);
        $rulesHash = sha1($systemInstruction);
        $fingerprint = $this->getFileFingerprint($analysis->getDocumentForAnalysis());

        if ($analysis->isGeminiCacheValid($fingerprint, $rulesHash)) {
            return $analysis->gemini_cache_id;
        }

        // Создаем кэш в Google
        $cacheResponse = $this->cachedContents()->create(
            model: "models/gemini-1.5-flash",
            systemInstruction: \Gemini\Data\Content::parse($systemInstruction),
            ttl: "{$ttlHours}h",
            contents: [\Gemini\Data\Content::parse(part: "DOC_URI: {$fileUri}")],
        );

        $cacheId = $cacheResponse->cachedContent->name;

        $analysis->update([
            'gemini_cache_id' => $cacheId,
            'gemini_rules_hash' => $rulesHash,
            'gemini_cache_expires_at' => now()->addHours($ttlHours),
        ]);

        return $cacheId;
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
        if ($mime === null) {
            throw new InvalidArgumentException("Unsupported MIME type: {$mimeType}");
        }

        $fileResponse = $this->files()->upload(filename: $path, mimeType: $mime, displayName: $displayName);

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
