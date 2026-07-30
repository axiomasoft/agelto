<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Dto\Procurement\Analyzers\Estimate\PassportResponse;
use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\DocumentStatus;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\DatasetExampleStoreRepository;
use App\Repositories\Procurement\DocumentStoreRepository;
use App\Support\Files\MimeDetector;
use App\Support\Files\ShardedBlobPath;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use JsonException;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * Импорт пары «файл + gold» контракта P2.2 (`estimate:dataset:import`, вынесено из
 * `DatasetImportCommand`, P3.3): каждый эталон валидируется контрактом P2.2
 * (`PassportResponse`/`EstimateRow`) ДО записи — битый JSON отклоняется с перечнем ошибок,
 * остальные пары импорта не затрагивает. Документ/версия создаются вне краулинга
 * (`procurement_id=null`, D22).
 */
final readonly class DatasetImporter
{
    public function __construct(
        private DocumentStoreRepository $documents,
        private DatasetExampleStoreRepository $examples,
    ) {}

    /**
     * @return array{errors: list<string>, normalized: array<string, mixed>|null}
     */
    public function validateGold(string $rawJson): array
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
    public function importPair(
        string $baseName,
        string $sourceFile,
        array $normalizedGold,
        AiDocumentType $documentType,
        string $task,
        string $source,
        int $goldVersion,
    ): void {
        $content = (string) file_get_contents($sourceFile);
        $hash = hash('sha256', $content);
        $externalId = "gold:{$baseName}";

        $document = $this->documents->findByExternalId(null, $externalId)
            ?? $this->documents->create([
                'procurement_id' => null,
                'platform_id' => Platform::training()->id,
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
                'mime' => MimeDetector::of($content),
                'storage_path' => $this->store($hash, $content),
                'downloaded_at' => now(),
            ]);

        $this->examples->storeOrUpdate($version->id, $task, $goldVersion, [
            'document_type' => $documentType->value,
            'gold' => $normalizedGold,
            'source' => $source,
        ]);
    }

    private function store(string $hash, string $content): string
    {
        $disk = (string) config('crawler.documents_disk');
        $path = ShardedBlobPath::for($hash);

        if (! Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->put($path, $content);
        }

        return $path;
    }
}
