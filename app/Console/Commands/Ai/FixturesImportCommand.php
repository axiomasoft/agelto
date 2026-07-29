<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Dto\Procurement\Ai\Fixture\FixtureManifestEntry;
use App\Repositories\Procurement\FixtureStoreRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use JsonException;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * Импорт `estimates/manifest.json` (D1/D20, P2.3): читает манифест, валидирует КАЖДУЮ запись
 * контрактом `FixtureManifestEntry`, кладёт файл эталона на диск `config('ai.fixtures.disk')` по
 * `<CODE>/source.<ext>` (беря его из хранилища, если он там уже есть и совпал по sha256, иначе из
 * `--source-dir`), апсертит запись реестра `prc_ai_fixtures`. Идемпотентность — по sha256: повтор
 * не перезаливает файл и не плодит записи; битая запись отклоняется индивидуально, не роняя
 * остальные (тот же приём, что `DatasetImportCommand`).
 */
final class FixturesImportCommand extends Command
{
    protected $signature = 'ai:fixtures:import
        {--manifest= : путь к manifest.json (дефолт config(ai.fixtures.manifest_path))}
        {--source-dir= : откуда брать файлы, которых нет в хранилище (дефолт config(ai.fixtures.source_dir))}
        {--dry-run : только проверить, ничего не писать}';

    protected $description = 'Импорт эталонного корпуса из manifest.json в хранилище и реестр prc_ai_fixtures';

    public function __construct(private readonly FixtureStoreRepository $fixtures)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $manifestPath = (string) ($this->option('manifest') ?? config('ai.fixtures.manifest_path'));
        $sourceDir = (string) ($this->option('source-dir') ?? config('ai.fixtures.source_dir'));
        $dryRun = (bool) $this->option('dry-run');
        $disk = (string) config('ai.fixtures.disk');

        if (! is_file($manifestPath)) {
            $this->error("Манифест не найден: {$manifestPath}");

            return self::FAILURE;
        }

        try {
            $decoded = json_decode((string) file_get_contents($manifestPath), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("Манифест не распарсен: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! is_array($decoded) || ! is_array($decoded['fixtures'] ?? null)) {
            $this->error('Ожидается объект вида {"version": 1, "fixtures": [...]}');

            return self::FAILURE;
        }

        $imported = 0;
        $failed = 0;

        foreach (array_values($decoded['fixtures']) as $index => $entryJson) {
            if (! is_array($entryJson)) {
                $this->error("fixtures.{$index}: ожидается объект");
                $failed++;

                continue;
            }

            $errors = $this->importEntry($entryJson, $index, $disk, $sourceDir, $dryRun);

            if ($errors !== []) {
                foreach ($errors as $error) {
                    $this->error($error);
                }
                $failed++;

                continue;
            }

            $code = (string) $entryJson['code'];
            $this->info($dryRun ? "{$code}: ok (dry-run)" : "{$code}: импортирован.");
            $imported++;
        }

        $this->info("Готово: импортировано {$imported}, отклонено {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $entryJson
     * @return list<string>
     */
    private function importEntry(array $entryJson, int $index, string $disk, string $sourceDir, bool $dryRun): array
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
