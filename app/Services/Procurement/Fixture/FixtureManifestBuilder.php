<?php

declare(strict_types=1);

namespace App\Services\Procurement\Fixture;

use App\Exceptions\Procurement\Ai\ManifestUnreadable;
use App\Models\Procurement\Ai\Fixture;
use App\Support\Files\MimeDetector;
use JsonException;

/**
 * Построение записей `estimates/manifest.json` из шорт-листа P1.3 (`estimate:fixtures:manifest`, D1/D20):
 * `storage_path` пересчитывается построителем `Fixture::storagePathFor()`, `sha256`/`size_bytes`
 * из шорт-листа ПРОВЕРЯЮТСЯ пересчётом по файлу-источнику. Запись, чей `code` уже есть в манифесте,
 * НЕ трогается без `$force`.
 */
final readonly class FixtureManifestBuilder
{
    /**
     * @param  list<mixed>  $shortlist
     * @param  array<string, array<string, mixed>>  $existing  keyed по code
     * @param  array<string, string>  $mimeByPath
     * @return array{records: array<string, array<string, mixed>>, errors: list<string>}
     */
    public function build(array $shortlist, array $existing, string $sourceDir, array $mimeByPath, bool $force): array
    {
        $errors = [];

        foreach ($shortlist as $index => $row) {
            if (! is_array($row)) {
                $errors[] = "shortlist.{$index}: ожидается объект";

                continue;
            }

            $code = $this->stringValue($row['code'] ?? '', "shortlist.{$index}.code");

            if ($code === '') {
                $errors[] = "shortlist.{$index}: отсутствует code";

                continue;
            }

            if (isset($existing[$code]) && ! $force) {
                continue;
            }

            $sourcePath = $this->stringValue($row['source_path'] ?? '', "{$code}.source_path");
            $extension = strtolower($this->stringValue($row['format'] ?? '', "{$code}.format"));
            $absoluteSourcePath = "{$sourceDir}/{$sourcePath}";

            if (! is_file($absoluteSourcePath)) {
                $errors[] = "{$code}: файл-источник не найден ({$absoluteSourcePath})";

                continue;
            }

            $content = file_get_contents($absoluteSourcePath);

            if ($content === false) {
                $errors[] = "{$code}: файл-источник не удалось прочитать ({$absoluteSourcePath})";

                continue;
            }
            $actualHash = hash('sha256', $content);
            $actualSize = strlen($content);

            if ($actualHash !== ($row['sha256'] ?? null)) {
                $errors[] = "{$code}: sha256 шорт-листа не совпадает с файлом-источником ({$absoluteSourcePath})";

                continue;
            }

            if ($actualSize !== ($row['size_bytes'] ?? null)) {
                $errors[] = "{$code}: size_bytes шорт-листа не совпадает с файлом-источником ({$absoluteSourcePath})";

                continue;
            }

            $goldSource = $this->stringValue($row['gold_source'] ?? 'none', "{$code}.gold_source");
            // Ось типа документа (D16/D17, §6.1 document-type-axis.md): шорт-лист несёт её ключом
            // document_type; «владелец корпуса» без явного значения в строке равен фактическому
            // типу (запись без document_type отклоняется валидацией FixtureManifestEntry).
            $documentType = $this->stringValue($row['document_type'] ?? '', "{$code}.document_type");
            $ownerDocumentType = $this->stringValue($row['owner_document_type'] ?? $documentType, "{$code}.owner_document_type");
            $title = $this->stringValue($row['title'] ?? pathinfo($sourcePath, PATHINFO_FILENAME), "{$code}.title");

            $existing[$code] = [
                'code' => $code,
                'storage_path' => Fixture::storagePathFor($code, $extension),
                'source_path' => $sourcePath,
                'original_filename' => basename($sourcePath),
                'sha256' => $actualHash,
                'size_bytes' => $actualSize,
                'mime' => $mimeByPath[$sourcePath] ?? MimeDetector::of($content),
                'class' => strtolower($this->stringValue($row['class'] ?? '', "{$code}.class")),
                'source_format' => $extension,
                'form' => $this->stringValue($row['form'] ?? '', "{$code}.form"),
                'generator' => $row['generator'] ?? null,
                'cluster_id' => $row['cluster_id'] ?? null,
                'document_type' => $documentType,
                'owner_document_type' => $ownerDocumentType,
                'title' => $title,
                'quirks' => array_values((array) ($row['quirks'] ?? [])),
                'expected_estimates' => $row['expected_estimates'] ?? null,
                'expected_rows' => null,
                'gold_source' => $goldSource,
                'gold_path' => null,
                'is_verified' => false,
                'notes' => $row['selection_reason'] ?? null,
            ];
        }

        return ['records' => $existing, 'errors' => $errors];
    }

    /**
     * Манифест, ещё не существующий на диске, — штатное состояние ДО первой генерации, не ошибка
     * ({@see ManifestUnreadable} — для УЖЕ существующего, но битого).
     *
     * @return array<string, array<string, mixed>>
     */
    public function readExistingManifest(string $manifestPath): array
    {
        if (! is_file($manifestPath)) {
            return [];
        }

        try {
            $content = file_get_contents($manifestPath);

            if ($content === false) {
                return [];
            }

            $decoded = $this->jsonObject(json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR), 'manifest');
        } catch (JsonException) {
            return [];
        }

        $fixtures = $decoded['fixtures'] ?? null;

        if (! is_array($fixtures)) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $byCode */
        $byCode = [];

        foreach ($fixtures as $entry) {
            if (! is_array($entry) || ! array_key_exists('code', $entry)) {
                continue;
            }

            /** @var array<string, mixed> $entry */
            $code = $this->stringValue($entry['code'], 'manifest fixture code');
            $byCode[$code] = $entry;
        }

        return $byCode;
    }

    /**
     * @return array<string, string>
     */
    public function mimeIndex(string $inventoryPath): array
    {
        if ($inventoryPath === '' || ! is_file($inventoryPath)) {
            return [];
        }

        try {
            $content = file_get_contents($inventoryPath);

            if ($content === false) {
                return [];
            }

            $decoded = $this->jsonObject(json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR), 'inventory');
        } catch (JsonException) {
            return [];
        }

        $byPath = [];

        $files = $decoded['files'] ?? [];

        if (! is_array($files)) {
            return [];
        }

        foreach ($files as $file) {
            if (is_array($file) && isset($file['path'], $file['mime']) && is_string($file['path']) && is_string($file['mime'])) {
                $byPath[$file['path']] = $file['mime'];
            }
        }

        return $byPath;
    }

    private function stringValue(mixed $value, string $context): string
    {
        if (! is_string($value)) {
            throw new ManifestUnreadable("{$context} должно быть строкой.");
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function jsonObject(mixed $value, string $context): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new ManifestUnreadable("{$context} должен быть JSON-объектом.");
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
