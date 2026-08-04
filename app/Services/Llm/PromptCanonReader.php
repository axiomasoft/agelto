<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Единственный владелец обхода канона промтов и разбора front-matter (D22, D32,
 * `root/service-layer-map.md` §7) — файлы под git являются каноном, БД её рантайм-копия (D5/D17).
 * Два корня: `resources/ai/prompts/**\/*.md` (кросс-документное ядро) и
 * `resources/<домен>/ai/prompts/**\/*.md` (тип-владелец, `document_type` из front-matter — источник
 * истины); соответствие «корень ↔ document_type» проверяется на каждом разборе.
 */
final readonly class PromptCanonReader
{
    /**
     * Оба корня канона по D32: ключ — глоб-шаблон относительно `resources/`, значение — признак
     * доменного корня (`true`) против кросс-документного ядра (`false`).
     *
     * @var array<string, bool>
     */
    private const array ROOT_GLOBS = [
        'ai/prompts/*/*.md' => false,
        '*/ai/prompts/*/*.md' => true,
    ];

    public function __construct(private DocumentAnalyzerRegistry $registry) {}

    /**
     * @return Collection<int, array{document_type: string, key: string, step: string, content: string, version: int, priority: int, is_active: bool}>
     */
    public function read(): Collection
    {
        return collect($this->discoverPaths())
            ->map(fn (array $entry): array => $this->parseFile($entry['path'], $entry['is_domain_root']));
    }

    /**
     * Путь к файлу канона с данным `key` в ОБЪЕДИНЁННОМ пространстве обоих корней (D32). `null` —
     * ключа нет ни в одном корне. Ключи обязаны быть уникальны в объединённом пространстве — находка
     * ДВУХ файлов с одним `key` не решается «взять первый» (`root/service-layer-map.md` §7), это
     * находка, требующая эскалации.
     */
    public function findPathByKey(string $key): ?string
    {
        $matches = array_values(array_filter(
            $this->discoverPaths(),
            fn (array $entry): bool => $this->parseFile($entry['path'], $entry['is_domain_root'])['key'] === $key,
        ));

        if (count($matches) > 1) {
            $paths = array_map(static fn (array $entry): string => $entry['path'], $matches);

            throw new RuntimeException("Ключ промта «{$key}» встречается больше одного раза в объединённом пространстве корней канона: ".implode(', ', $paths));
        }

        return $matches[0]['path'] ?? null;
    }

    /**
     * @return list<array{path: string, is_domain_root: bool}>
     */
    private function discoverPaths(): array
    {
        $entries = [];

        foreach (self::ROOT_GLOBS as $pattern => $isDomainRoot) {
            foreach (glob(resource_path($pattern)) ?: [] as $path) {
                $entries[] = ['path' => $path, 'is_domain_root' => $isDomainRoot];
            }
        }

        usort($entries, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);

        return $entries;
    }

    /**
     * @return array{document_type: string, key: string, step: string, content: string, version: int, priority: int, is_active: bool}
     */
    private function parseFile(string $path, bool $isDomainRoot): array
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

        // Соответствие «корень ↔ document_type» (D32, Implementation Rule 6 P3.4): кросс-документный
        // файл (document_type=any) обязан лежать в корне ядра, тип-владелец — в доменном корне.
        // Захардкоженной карты «тип → каталог» здесь нет: домен подтверждает СЕБЯ фактом попадания
        // в свой корень, а этот факт сверяется с front-matter — источником истины о владельце.
        if ($isDomainRoot && $documentType === null) {
            throw new RuntimeException("Файл кросс-документного назначения (document_type=any) лежит в доменном корне канона промтов — нарушает D32: {$path}");
        }

        if (! $isDomainRoot && $documentType !== null) {
            throw new RuntimeException("Файл с document_type «{$documentType->value}» лежит в корне ядра канона промтов — нарушает D32 (типу-владельцу нужен доменный корень): {$path}");
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
