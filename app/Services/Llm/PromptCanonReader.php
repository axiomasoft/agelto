<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\AiDocumentType;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Единственный владелец обхода `resources/ai/prompts/**\/*.md` и разбора front-matter (D22,
 * `root/service-layer-map.md` §7) — файлы под git являются каноном, БД её рантайм-копия (D5/D17).
 */
final readonly class PromptCanonReader
{
    public function __construct(private DocumentAnalyzerRegistry $registry) {}

    /**
     * @return Collection<int, array{document_type: string, key: string, step: string, content: string, version: int, priority: int, is_active: bool}>
     */
    public function read(): Collection
    {
        $paths = glob(resource_path('ai/prompts/*/*.md')) ?: [];

        return collect($paths)
            ->sort()
            ->values()
            ->map(fn (string $path): array => $this->parseFile($path));
    }

    /**
     * @return array{document_type: string, key: string, step: string, content: string, version: int, priority: int, is_active: bool}
     */
    private function parseFile(string $path): array
    {
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException("Не удалось прочитать файл промта: {$path}");
        }

        if (! preg_match('/\A---\n(?<front>.*?)\n---\n+(?<content>.*)\z/s', $raw, $matches)) {
            throw new RuntimeException("Файл промта без front-matter (---...---): {$path}");
        }

        $front = [];
        foreach (preg_split('/\n/', trim($matches['front'])) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $front[trim($name)] = trim($value);
        }

        foreach (['document_type', 'key', 'step', 'version', 'priority', 'is_active'] as $required) {
            if (! array_key_exists($required, $front)) {
                throw new RuntimeException("Файл промта без обязательного поля front-matter «{$required}»: {$path}");
            }
        }

        // Fail fast на опечатку в document_type — AiDocumentType либо сентинел any (§1.2/§4.1
        // root/document-type-axis.md).
        $documentType = AiDocumentType::tryFrom($front['document_type']);

        if ($documentType === null && $front['document_type'] !== DocumentTypeScope::Any->value) {
            throw new RuntimeException("Файл промта с неизвестным document_type «{$front['document_type']}»: {$path}");
        }

        // Fail fast на опечатку в step — обязана совпадать с одним из значений реестра для
        // ЭТОГО document_type (ядерные ∪ собственные значения его анализатора, P2.5 §7 контракта).
        $this->registry->promptStepFor($documentType ?? DocumentTypeScope::Any, $front['step']);

        $content = trim($matches['content']);

        if ($content === '') {
            throw new RuntimeException("Файл промта с пустым содержимым (после front-matter): {$path}");
        }

        return [
            'document_type' => $front['document_type'],
            'key' => $front['key'],
            'step' => $front['step'],
            'content' => $content,
            'version' => (int) $front['version'],
            'priority' => (int) $front['priority'],
            'is_active' => filter_var($front['is_active'], FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
