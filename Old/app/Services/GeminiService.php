<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DocumentAnalysis\DocumentAnalysis;
use Exception;
use Gemini;
use Gemini\Client;
use Gemini\Data\Content;
use Gemini\Data\GenerationConfig;
use Gemini\Enums\MimeType as GeminiMimeType;
use Gemini\Enums\ResponseMimeType;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @mixin Client
 */
final class GeminiService
{
    private Client $nativeClient;

    private string $model = 'gemini-2.0-flash';

    /**
     * Расход токенов последнего успешного запроса генерации (prompt/completion/total).
     *
     * @var array<string, int|null>|null
     */
    private ?array $lastUsage = null;

    public function __construct()
    {
        $this->buildClient();
    }

    public function __call(string $name, array $arguments)
    {
        return $this->nativeClient->$name(...$arguments);
    }

    /**
     * Извлекает расход токенов из ответа Gemini (usageMetadata). Null-устойчиво к версии SDK.
     *
     * @return array<string, int|null>|null
     */
    public static function usageFromResponse(object $response): ?array
    {
        $usage = $response->usageMetadata ?? null;

        if ($usage === null) {
            return null;
        }

        return [
            'prompt_tokens' => $usage->promptTokenCount ?? null,
            'completion_tokens' => $usage->candidatesTokenCount ?? null,
            'total_tokens' => $usage->totalTokenCount ?? null,
        ];
    }

    /**
     * Токены последнего успешного generateJson() — для учёта стоимости в DocumentAnalysisResponse.
     *
     * @return array<string, int|null>|null
     */
    public function lastUsage(): ?array
    {
        return $this->lastUsage;
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
                'gemini_cache_expires_at' => null,
            ]);
        }

        $fileUri = $media->getCustomProperty('google_file_uri');

        if (! $this->isFileActive($fileUri)) {
            $fileUri = $this->uploadFile($media->getPath(), $media->file_name);
            $media->setCustomProperty('google_file_uri', $fileUri);
            $media->save();
        }

        return $fileUri;
    }

    /**
     * Ручное удаление кэша (вызываем в конце Detailed Analysis)
     */
    public function deleteCache(DocumentAnalysis $analysis): void
    {
        if ($analysis->gemini_cache_id) {
            try {
                $this->cachedContents()->delete($analysis->gemini_cache_id);
            } catch (Exception $e) {
            }

            $analysis->update([
                'gemini_cache_id' => null,
                'gemini_cache_expires_at' => null,
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

        if ($analysis->isCacheValid($fingerprint, $rulesHash)) {
            return $analysis->gemini_cache_id;
        }

        // Создаем кэш в Google
        $cacheResponse = $this->cachedContents()->create(
            model: 'models/gemini-1.5-flash',
            systemInstruction: Content::parse($systemInstruction),
            ttl: "{$ttlHours}h",
            contents: [Content::parse(part: "DOC_URI: {$fileUri}")],
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
        $this->lastUsage = null;

        try {
            $model = $this->generativeModel($this->model);
            $config = new GenerationConfig(
                responseMimeType: ResponseMimeType::APPLICATION_JSON,
                temperature: 0.1,
            );

            // Троттлинг на уровне ключа: не больше N генераций в окно на всех воркеров (анти-429).
            $response = $this->withRateLimit(function () use ($model, $config, $cacheId, $systemInstruction, $prompt, $data) {
                if ($cacheId) {
                    // Экономный запрос через существующий кеш
                    return $model->withCachedContent($cacheId)
                        ->withGenerationConfig($config)
                        ->generateContent($prompt);
                }

                // Обычный запрос (передаем инструкции и URI файла)
                if ($systemInstruction) {
                    $model = $model->withSystemInstruction(Content::parse($systemInstruction));
                }

                return $model->withGenerationConfig($config)->generateContent([
                    "PROMPT:\n{$prompt}",
                    "DATA:\n{$data}",
                ]);
            });

            $this->lastUsage = self::usageFromResponse($response);

            return json_decode($response->text(), true) ?? [];
        } catch (Exception $e) {
            Log::error('Gemini Service Error: '.$e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function uploadFile(string $path, string $displayName, string $mimeType = 'application/pdf'): string
    {
        $mime = GeminiMimeType::tryFrom($mimeType);
        if ($mime === null) {
            throw new InvalidArgumentException("Unsupported MIME type: {$mimeType}");
        }

        // ТВОЙ СИНТАКСИС: передача через позиционные аргументы
        $fileResponse = $this->nativeClient->files()->upload(
            filename: $path,
            mimeType: $mime,
            displayName: $displayName
        );

        // Извлекаем чистый ID, чтобы SDK не дублировал префиксы
        $fileId = last(explode('/', $fileResponse->name));

        $start = now();
        while (true) {
            // Обязательная пауза, чтобы Google успел проиндексировать файл
            sleep(3);

            try {
                // Вызываем метаданные по ID (SDK сам подставит /v1beta/files/)
                $currentFile = $this->nativeClient->files()->metadataGet($fileId);

                // Используем Enum для надежности (как в твоем дампе)
                $state = is_string($currentFile->state) ? $currentFile->state : $currentFile->state->value;

                if ($state === 'ACTIVE') {
                    return $currentFile->uri;
                }

                if ($state === 'FAILED') {
                    throw new Exception("Gemini File API failed: {$displayName}");
                }
            } catch (Gemini\Exceptions\UnserializableResponse $e) {
                // Если прокси вернул пустой ответ или мусор — просто пробуем еще раз
                if (now()->diffInMinutes($start) > 5) {
                    throw $e;
                }

                continue;
            }

            if (now()->diffInMinutes($start) > 5) {
                throw new Exception("Timeout waiting for file: {$displayName}");
            }
        }
    }

    public function isFileActive(?string $fileUri): bool
    {
        if (! $fileUri) {
            return false;
        }

        try {
            // Извлекаем только ID из сохраненного URI
            $fileId = last(explode('/', $fileUri));

            // Запрашиваем метаданные без ручной склейки 'files/'
            $file = $this->nativeClient->files()->metadataGet($fileId);
            $state = is_string($file->state) ? $file->state : $file->state->value;

            return $state === 'ACTIVE';
        } catch (Exception $e) {
            Log::warning("File check failed for {$fileUri}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Оборачивает реальный сетевой вызов троттлингом Redis::throttle.
     * Выключен (max<=0) и в юнит-тестах (нет redis) — выполняем напрямую.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withRateLimit(callable $callback)
    {
        $max = (int) config('gemini.rate_limit.max', 0);

        if ($max <= 0 || app()->runningUnitTests()) {
            return $callback();
        }

        $every = (int) config('gemini.rate_limit.every_seconds', 60);
        $block = (int) config('gemini.rate_limit.block_seconds', 30);

        return Redis::throttle('gemini:generate')
            ->allow($max)
            ->every($every)
            ->block($block)
            ->then(
                $callback,
                static function (): void {
                    throw new Exception('Gemini rate limit: слот не получен за отведённое время.');
                },
            );
    }

    private function getFileFingerprint(Media $media): string
    {
        $path = $media->getPath();

        return md5($media->file_name.filesize($path).filemtime($path));
    }

    private function buildClient(): void
    {
        $httpClient = new GuzzleClient([
            'proxy' => config('sys.proxies.gemini_proxy'),
            'verify' => true,
            'timeout' => 300,
            'http_errors' => true,
        ]);

        $this->nativeClient = Gemini::factory()
            ->withApiKey(config('gemini.api_key'))
            ->withHttpClient($httpClient)
            ->make();
    }
}
