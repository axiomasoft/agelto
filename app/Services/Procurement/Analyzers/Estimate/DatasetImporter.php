<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Dto\Procurement\Analyzers\Estimate\PassportResponse;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Ai\DatasetExampleStoreRepository;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Services\Storage\FileLocationResolver;
use App\Support\Files\MimeDetector;
use App\Support\Files\ShardedBlobPath;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use JsonException;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * Импорт пары «файл + gold» контракта (`estimate:dataset:import`, вынесено из
 * `DatasetImportCommand`): каждый эталон валидируется контрактом
 * (`PassportResponse`/`EstimateRow`) ДО записи — битый JSON отклоняется с перечнем ошибок,
 * остальные пары импорта не затрагивает. Документ/версия создаются вне краулинга
 * (`procurement_id=null`).
 */
final readonly class DatasetImporter
{
    public function __construct(
        private DocumentStoreRepository $documents,
        private DatasetExampleStoreRepository $examples,
        private FileLocationResolver $locationResolver,
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
            $passport = $this->validateDto(
                static fn (): PassportResponse => PassportResponse::validateAndCreate(Arr::except($estimateJson, ['rows'])),
                "estimates.{$index}",
                $errors,
            );

            $rows = [];

            foreach (array_values((array) $rowsJson) as $rowIndex => $rowJson) {
                if (! is_array($rowJson) || array_is_list($rowJson)) {
                    $errors[] = "estimates.{$index}.rows.{$rowIndex}: ожидается объект";

                    continue;
                }

                /** @var array<string, mixed> $rowJson */
                $row = $this->validateDto(
                    static fn (): EstimateRow => EstimateRow::validateAndCreate($rowJson),
                    "estimates.{$index}.rows.{$rowIndex}",
                    $errors,
                );

                if ($row instanceof EstimateRow) {
                    $rows[] = $row;
                }
            }

            if (! $passport instanceof PassportResponse || count($rows) !== count((array) $rowsJson)) {
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
     * @template T
     *
     * @param  callable(): T  $factory
     * @param  list<string>  $errors
     * @return T|null
     */
    private function validateDto(callable $factory, string $prefix, array &$errors): mixed
    {
        try {
            return $factory();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $errors[] = "{$prefix}.{$field}: ".implode('; ', $this->messages($messages));
            }
        } catch (CannotCreateData $e) {
            $errors[] = "{$prefix}: {$e->getMessage()}";
        }

        return null;
    }

    /** @return list<string> */
    private function messages(mixed $messages): array
    {
        if (! is_array($messages)) {
            return [];
        }

        return array_values(array_filter($messages, is_string(...)));
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
        bool $verified = false,
    ): void {
        $content = file_get_contents($sourceFile);

        if ($content === false) {
            throw new \RuntimeException("Не удалось прочитать исходный файл эталона: {$sourceFile}");
        }
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
                'disk' => $this->locationResolver->currentDocumentsDisk(),
                'downloaded_at' => now(),
            ]);

        $this->examples->storeOrUpdate(
            documentVersionId: $version->id,
            task: $task,
            version: $goldVersion,
            attributes: [
                'document_type' => $documentType->value,
                'gold' => $normalizedGold,
                'source' => $source,
                'verified_by' => null,
                'verified_at' => $verified ? now() : null,
            ],
        );
    }

    private function store(string $hash, string $content): string
    {
        $disk = $this->locationResolver->currentDocumentsDisk();
        $path = ShardedBlobPath::for($hash);

        if (! Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->put($path, $content);
        }

        return $path;
    }
}
