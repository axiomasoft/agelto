<?php

declare(strict_types=1);

namespace App\Services;

use Gemini;
use Gemini\Client;
use Gemini\Data\Content;
use Gemini\Data\GenerationConfig;
use Gemini\Enums\ResponseMimeType;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;

/**
 * @mixin Client
 */
class GeminiService
{
    private Client $nativeClient;
    protected string $model = 'gemini-2.0-flash';

    public function __construct()
    {
        $this->buildClient();
    }

    private function buildClient(): void
    {
        $httpClient = new GuzzleClient([
            'proxy'   => config('app.proxy.base'),
            'verify'  => false,
            'timeout' => 120,
        ]);

        $this->nativeClient = Gemini::factory()
            ->withApiKey(config('gemini.api_key'))
            ->withHttpClient($httpClient)
            ->make();
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

            $response = $model->withGenerationConfig($config)->generateContent([
                "PROMPT:\n{$prompt}",
                "DATA:\n{$data}"
            ]);

            return json_decode($response->text(), true) ?? [];
        } catch (\Exception $e) {
            Log::error("Gemini Extended Client Error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * МАГИЯ: Делегирование всех стандартных методов (chat, embed и т.д.) нативному клиенту
     */
    public function __call(string $name, array $arguments)
    {
        return $this->nativeClient->$name(...$arguments);
    }

    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }
}
