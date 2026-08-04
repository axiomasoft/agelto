<?php

declare(strict_types=1);

namespace App\Services\Procurement\Fixture;

use App\Dto\Procurement\Fixture\FixtureManifestEntry;
use App\Repositories\Procurement\Ai\FixtureStoreRepository;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * Импорт ОДНОЙ записи манифеста в хранилище и реестр `prc_ai_fixtures` (`estimate:fixtures:import`,
 * D1/D20/P2.3): идемпотентность — по sha256, битая запись отклоняется индивидуально, не роняя
 * остальные партии.
 */
final readonly class FixtureRegistrar
{
    public function __construct(private FixtureStoreRepository $fixtures) {}

    /**
     * @param  array<string, mixed>  $entryJson
     * @return list<string>
     */
    public function import(array $entryJson, int $index, string $disk, string $sourceDir, bool $dryRun): array
    {
        try {
            $entry = FixtureManifestEntry::validateAndCreate($entryJson);
        } catch (ValidationException $e) {
            $errors = [];

            foreach ($e->errors() as $field => $messages) {
                $errors[] = "fixtures.{$index}.{$field}: ".implode('; ', $messages);
            }

            return $errors;
        } catch (CannotCreateData $e) {
            return ["fixtures.{$index}: {$e->getMessage()}"];
        }

        $content = null;

        if (Storage::disk($disk)->exists($entry->storage_path)) {
            $existingHash = hash('sha256', Storage::disk($disk)->get($entry->storage_path));

            if ($existingHash !== $entry->sha256) {
                return ["fixtures.{$index}.sha256: файл на диске уже существует с другим содержимым (sha256 разошёлся — перезапись без ведома оператора запрещена)"];
            }
        } else {
            $sourcePath = $sourceDir.'/'.$entry->source_path;

            if ($entry->source_path === null || ! is_file($sourcePath)) {
                return ["fixtures.{$index}.source_path: файл-источник не найден ({$sourcePath})"];
            }

            $content = (string) file_get_contents($sourcePath);
            $actualHash = hash('sha256', $content);
            $actualSize = strlen($content);

            if ($actualHash !== $entry->sha256) {
                return ["fixtures.{$index}.sha256: не совпадает с файлом-источником ({$sourcePath})"];
            }

            if ($actualSize !== $entry->size_bytes) {
                return ["fixtures.{$index}.size_bytes: не совпадает с файлом-источником ({$sourcePath})"];
            }
        }

        if ($dryRun) {
            return [];
        }

        if ($content !== null) {
            Storage::disk($disk)->put($entry->storage_path, $content);
        }

        $this->fixtures->storeOrUpdate($entry->code, [
            'source_format' => $entry->source_format,
            'storage_path' => $entry->storage_path,
            'disk' => $disk,
            'sha256' => $entry->sha256,
            'size_bytes' => $entry->size_bytes,
            'mime' => $entry->mime ?? 'application/octet-stream',
            'original_filename' => $entry->original_filename ?? basename((string) $entry->source_path),
            'source_path' => (string) $entry->source_path,
            'generator' => $entry->generator,
            'cluster_id' => $entry->cluster_id,
            'document_type' => $entry->document_type,
            'owner_document_type' => $entry->owner_document_type,
            'title' => $entry->title,
            'quirks' => $entry->quirks,
            'type_profile' => [
                'class' => $entry->class->value,
                'form' => $entry->form,
                'expected_estimates' => $entry->expected_estimates,
                'expected_rows' => $entry->expected_rows,
                'gold_source' => $entry->gold_source->value,
            ],
            'is_verified' => $entry->is_verified,
            'notes' => $entry->notes,
        ]);

        return [];
    }
}
