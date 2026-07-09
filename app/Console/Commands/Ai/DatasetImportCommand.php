<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Dto\Procurement\Ai\Estimate\EstimateRow;
use App\Dto\Procurement\Ai\Estimate\PassportResponse;
use App\Enums\Procurement\DocumentStatus;
use App\Models\Procurement\Ai\DatasetExample;
use App\Repositories\Procurement\DocumentStoreRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use JsonException;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * Импорт папки эталонных смет (P7.4, ТЗ 04 §5): пары `<name>.<ext>` (исходный файл — pdf/xlsx/
 * скан) + `<name>.gold.json` (эталонный разбор, формат — `docs/specs/v2/08-estimate-extraction.md`
 * §8). Каждый эталон валидируется контрактом P2.2 (`PassportResponse`/`EstimateRow`) ДО записи —
 * битый JSON отклоняется с перечнем ошибок, остальные пары импорта не затрагивает. Документ/версия
 * создаются вне краулинга (`procurement_id=null`, D22) — та же точка расширения, что подготовили
 * `DocumentStoreRepository::findByExternalId()`/`ArchiveStrategy` (P4.2).
 */
final class DatasetImportCommand extends Command
{
    protected $signature = 'ai:dataset:import
        {path : путь к папке с парами <name>.<ext> + <name>.gold.json}
        {--task=extraction : значение prc_ai_dataset_examples.task}
        {--source=manual : значение prc_ai_dataset_examples.source}
        {--gold-version=1 : версия эталона (upsert-ключ вместе с document_version_id+task; `--version` зарезервирован Symfony Console)}';

    protected $description = 'Импорт папки эталонных смет в prc_ai_dataset_examples (gold, P2.2-контракт)';

    public function __construct(private readonly DocumentStoreRepository $documents)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $folder = rtrim((string) $this->argument('path'), '/');

        if (! is_dir($folder)) {
            $this->error("Папка не найдена: {$folder}");

            return self::FAILURE;
        }

        $goldFiles = glob("{$folder}/*.gold.json") ?: [];

        if ($goldFiles === []) {
            $this->warn("В {$folder} не найдено ни одного *.gold.json — импортировать нечего.");

            return self::SUCCESS;
        }

        $imported = 0;
        $failed = 0;

        foreach ($goldFiles as $goldPath) {
            $baseName = basename($goldPath, '.gold.json');
            $sourceFile = $this->findSourceFile($folder, $baseName);

            if ($sourceFile === null) {
                $this->error("{$baseName}: не найден файл-источник ({$baseName}.<ext> рядом с {$baseName}.gold.json)");
                $failed++;

                continue;
            }

            $validation = $this->validateGold((string) file_get_contents($goldPath));

            if ($validation['errors'] !== []) {
                $this->error("{$baseName}: gold-эталон не прошёл валидацию контракта P2.2:");
                foreach ($validation['errors'] as $error) {
                    $this->line("  - {$error}");
                }
                $failed++;

                continue;
            }

            $this->importPair($baseName, $sourceFile, $validation['normalized']);
            $this->info("{$baseName}: импортирован.");
            $imported++;
        }

        $this->info("Готово: импортировано {$imported}, отклонено {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function findSourceFile(string $folder, string $baseName): ?string
    {
        foreach (glob("{$folder}/{$baseName}.*") ?: [] as $candidate) {
            if (! str_ends_with($candidate, '.gold.json')) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{errors: list<string>, normalized: array<string, mixed>|null}
     */
    private function validateGold(string $rawJson): array
    {
        try {
            $decoded = json_decode($rawJson, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return ['errors' => ["JSON не распарсен: {$e->getMessage()}"], 'normalized' => null];
        }

        if (! is_array($decoded) || ! is_array($decoded['estimates'] ?? null)) {
            return ['errors' => ['ожидается объект вида {"estimates": [...]} (docs/specs/v2/08-estimate-extraction.md §8)'], 'normalized' => null];
        }

        $errors = [];
        $normalizedEstimates = [];

        foreach (array_values($decoded['estimates']) as $index => $estimateJson) {
            if (! is_array($estimateJson)) {
                $errors[] = "estimates.{$index}: ожидается объект";

                continue;
            }

            $rowsJson = $estimateJson['rows'] ?? [];
            $passport = null;

            try {
                $passport = PassportResponse::validateAndCreate(Arr::except($estimateJson, ['rows']));
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    $errors[] = "estimates.{$index}.{$field}: ".implode('; ', $messages);
                }
            } catch (CannotCreateData $e) {
                $errors[] = "estimates.{$index}: {$e->getMessage()}";
            }

            $rows = [];

            foreach (array_values((array) $rowsJson) as $rowIndex => $rowJson) {
                try {
                    $rows[] = EstimateRow::validateAndCreate((array) $rowJson);
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        $errors[] = "estimates.{$index}.rows.{$rowIndex}.{$field}: ".implode('; ', $messages);
                    }
                } catch (CannotCreateData $e) {
                    $errors[] = "estimates.{$index}.rows.{$rowIndex}: {$e->getMessage()}";
                }
            }

            if ($passport === null || count($rows) !== count((array) $rowsJson)) {
                continue;
            }

            $normalizedEstimates[] = array_merge($passport->toArray(), [
                'ordinal' => $index + 1,
                'rows' => array_map(static fn (EstimateRow $row): array => $row->toArray(), $rows),
            ]);
        }

        if ($errors !== []) {
            return ['errors' => $errors, 'normalized' => null];
        }

        return ['errors' => [], 'normalized' => ['estimates' => $normalizedEstimates]];
    }

    /**
     * @param  array<string, mixed>  $normalizedGold
     */
    private function importPair(string $baseName, string $sourceFile, array $normalizedGold): void
    {
        $content = (string) file_get_contents($sourceFile);
        $hash = hash('sha256', $content);
        $externalId = "gold:{$baseName}";

        $document = $this->documents->findByExternalId(null, $externalId)
            ?? $this->documents->create([
                'procurement_id' => null,
                'external_id' => $externalId,
                'title' => basename($sourceFile),
                'kind' => null,
                'source_url' => null,
                'status' => DocumentStatus::Downloaded,
            ]);

        $version = $this->documents->findVersionByHash($document->id, $hash)
            ?? $this->documents->createVersion([
                'document_id' => $document->id,
                'content_hash' => $hash,
                'size_bytes' => strlen($content),
                'filename' => basename($sourceFile),
                'extension' => pathinfo($sourceFile, PATHINFO_EXTENSION),
                'mime' => $this->mimeOf($content),
                'storage_path' => $this->store($hash, $content),
                'downloaded_at' => now(),
            ]);

        DatasetExample::query()->updateOrCreate(
            [
                'document_version_id' => $version->id,
                'task' => (string) $this->option('task'),
                'version' => (int) $this->option('gold-version'),
            ],
            [
                'gold' => $normalizedGold,
                'source' => (string) $this->option('source'),
            ],
        );
    }

    private function store(string $hash, string $content): string
    {
        $disk = (string) config('crawler.documents_disk');
        $path = 'documents/'.substr($hash, 0, 2)."/{$hash}";

        if (! Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->put($path, $content);
        }

        return $path;
    }

    private function mimeOf(string $content): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo !== false ? finfo_buffer($finfo, $content) : false;

        return is_string($mime) ? $mime : 'application/octet-stream';
    }
}
