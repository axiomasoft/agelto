<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Models\Procurement\Ai\Fixture;
use Illuminate\Console\Command;
use JsonException;

/**
 * Генератор `estimates/manifest.json` из шорт-листа P1.3 (D1/D20, P2.4): `storage_path`
 * пересчитывается построителем `Fixture::storagePathFor()`, `sha256`/`size_bytes` берутся из
 * шорт-листа и ПРОВЕРЯЮТСЯ пересчётом по файлу-источнику (расхождение — ошибка, запись не
 * добавляется), `mime` — из инвентаря P1.1 (при отсутствии — `finfo` по файлу), `gold_path` —
 * всегда `null` (D91: указатель на файл gold пишет его производитель — `ai:gold:build
 * --update-manifest`, P5.3 — вместе с самим файлом; намерение несёт `gold_source`). Запись, чей
 * `code` уже есть в манифесте, НЕ трогается без `--force` — манифест SSOT и может быть поправлен
 * руками (D20).
 */
final class FixturesManifestCommand extends Command
{
    protected $signature = 'ai:fixtures:manifest
        {--from-shortlist= : путь к fixture-shortlist.json}
        {--inventory= : путь к P1-corpus-inventory.json для mime/размера}
        {--manifest= : куда писать (дефолт config(ai.fixtures.manifest_path))}
        {--force : перезаписать существующие записи}';

    protected $description = 'Собрать estimates/manifest.json из шорт-листа эталонов (P1.3)';

    public function handle(): int
    {
        $shortlistPath = (string) $this->option('from-shortlist');
        $manifestPath = (string) ($this->option('manifest') ?? config('ai.fixtures.manifest_path'));
        $sourceDir = (string) config('ai.fixtures.source_dir');
        $force = (bool) $this->option('force');

        if (! is_file($shortlistPath)) {
            $this->error("Шорт-лист не найден: {$shortlistPath}");

            return self::FAILURE;
        }

        try {
            $shortlist = json_decode((string) file_get_contents($shortlistPath), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("Шорт-лист не распарсен: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! is_array($shortlist)) {
            $this->error('Ожидается JSON-массив записей шорт-листа.');

            return self::FAILURE;
        }

        $mimeByPath = $this->mimeIndex((string) $this->option('inventory'));
        $existing = $this->readExistingManifest($manifestPath);

        $errors = [];

        foreach ($shortlist as $index => $row) {
            if (! is_array($row)) {
                $errors[] = "shortlist.{$index}: ожидается объект";

                continue;
            }

            $code = (string) ($row['code'] ?? '');

            if ($code === '') {
                $errors[] = "shortlist.{$index}: отсутствует code";

                continue;
            }

            if (isset($existing[$code]) && ! $force) {
                continue;
            }

            $sourcePath = (string) ($row['source_path'] ?? '');
            $extension = strtolower((string) ($row['format'] ?? ''));
            $absoluteSourcePath = "{$sourceDir}/{$sourcePath}";

            if (! is_file($absoluteSourcePath)) {
                $errors[] = "{$code}: файл-источник не найден ({$absoluteSourcePath})";

                continue;
            }

            $content = (string) file_get_contents($absoluteSourcePath);
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

            $goldSource = (string) ($row['gold_source'] ?? 'none');
            // Ось типа документа (D16/D17, §6.1 document-type-axis.md): шорт-лист несёт её ключом
            // document_type; «владелец корпуса» без явного значения в строке равен фактическому
            // типу (запись без document_type отклоняется валидацией FixtureManifestEntry, а не
            // тихо подставляется).
            $documentType = (string) ($row['document_type'] ?? '');
            $ownerDocumentType = (string) ($row['owner_document_type'] ?? $documentType);
            $title = (string) ($row['title'] ?? pathinfo($sourcePath, PATHINFO_FILENAME));

            $existing[$code] = [
                'code' => $code,
                'storage_path' => Fixture::storagePathFor($code, $extension),
                'source_path' => $sourcePath,
                'original_filename' => basename($sourcePath),
                'sha256' => $actualHash,
                'size_bytes' => $actualSize,
                'mime' => $mimeByPath[$sourcePath] ?? $this->mimeOf($content),
                'class' => strtolower((string) ($row['class'] ?? '')),
                'source_format' => $extension,
                'form' => (string) ($row['form'] ?? ''),
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

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        ksort($existing);

        $json = json_encode(
            ['version' => 1, 'fixtures' => array_values($existing)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        file_put_contents($manifestPath, $json.PHP_EOL);
        $this->info('Готово: '.count($existing).' запись(ей) в манифесте.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readExistingManifest(string $manifestPath): array
    {
        if (! is_file($manifestPath)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($manifestPath), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded) || ! is_array($decoded['fixtures'] ?? null)) {
            return [];
        }

        $byCode = [];

        foreach ($decoded['fixtures'] as $entry) {
            if (is_array($entry) && isset($entry['code'])) {
                $byCode[(string) $entry['code']] = $entry;
            }
        }

        return $byCode;
    }

    /**
     * @return array<string, string>
     */
    private function mimeIndex(string $inventoryPath): array
    {
        if ($inventoryPath === '' || ! is_file($inventoryPath)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($inventoryPath), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $byPath = [];

        foreach ((array) ($decoded['files'] ?? []) as $file) {
            if (is_array($file) && isset($file['path'], $file['mime'])) {
                $byPath[(string) $file['path']] = (string) $file['mime'];
            }
        }

        return $byPath;
    }

    private function mimeOf(string $content): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo !== false ? finfo_buffer($finfo, $content) : false;

        return is_string($mime) ? $mime : 'application/octet-stream';
    }
}
