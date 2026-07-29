<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Enums\Procurement\DocumentStatus;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\DocumentStoreRepository;
use App\Repositories\Procurement\FixtureStoreRepository;
use App\Services\Procurement\Gold\GoldValidator;
use App\Services\Procurement\Prepare\DocumentPreparer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * Рабочие копии эталонов + записи датасета (P5.5, D19/D45): по реестру `prc_ai_fixtures` создаёт
 * `Document`/`DocumentVersion` ВНЕ закупки (`procurement_id=null`, тот же приём, что
 * `DatasetImportCommand::importPair()`), под `--prepare` прогоняет `DocumentPreparer` синхронно и
 * записывает эталонные ОТВЕТЫ `estimates/gold/<CODE>.gold.json`/`<CODE>.screening.json` в
 * `prc_ai_dataset_examples` со ссылкой `fixture_id` и `source=deterministic`. Курируемый оригинал
 * (диск эталонов) и рабочая копия (`crawler.documents_disk`) — сознательно РАЗНЫЕ носители (D19):
 * эта команда создаёт только вторую, эталон на диске эталонов не трогает.
 */
final class GoldImportCommand extends Command
{
    protected $signature = 'ai:gold:import
        {--code=* : ограничить эталоны кодами}
        {--prepare : прогнать цепочку подготовки по созданным версиям}
        {--dry-run : ничего не писать}';

    protected $description = 'Создать рабочие копии эталонов и записи prc_ai_dataset_examples из gold-файлов корпуса';

    public function __construct(
        private readonly FixtureStoreRepository $fixtures,
        private readonly DocumentStoreRepository $documents,
        private readonly DocumentPreparer $preparer,
        private readonly GoldValidator $goldValidator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // `--prepare` прогоняет `SpreadsheetStrategy`/PhpSpreadsheet СИНХРОННО в этом же процессе
        // (не в дочернем, как `ConvertXlsxToPdfCommand`) — текстовая сетка на MERGE_HEAVY книгах
        // корпуса требует больше, чем дефолт xlsx-конвертации (P5.7-зонд использовал 1024M).
        ini_set('memory_limit', '1024M');

        $goldDir = (string) config('ai.fixtures.gold_dir');
        $fixturesDisk = (string) config('ai.fixtures.disk');
        $documentsDisk = (string) config('crawler.documents_disk');
        $dryRun = (bool) $this->option('dry-run');
        $prepare = (bool) $this->option('prepare');
        /** @var list<string> $onlyCodes */
        $onlyCodes = (array) $this->option('code');

        $fixtures = $this->fixtures->all()
            ->sortBy(fn (Fixture $fixture): string => $fixture->code)
            ->values();

        if ($onlyCodes !== []) {
            $fixtures = $fixtures->filter(fn (Fixture $fixture): bool => in_array($fixture->code, $onlyCodes, strict: true))->values();
        }

        $documentsCount = 0;
        $versionsCount = 0;
        $extractionCount = 0;
        $screeningCount = 0;
        $rejected = 0;
        $unprocessable = [];

        foreach ($fixtures as $fixture) {
            [$document, $version, $isNewDocument, $isNewVersion] = $this->resolveWorkingCopy($fixture, $fixturesDisk, $documentsDisk, $dryRun);

            if ($isNewDocument) {
                $documentsCount++;
            }
            if ($isNewVersion) {
                $versionsCount++;
            }

            if ($prepare && ! $dryRun) {
                $this->preparer->prepare($document, $version);

                if ($document->status === DocumentStatus::Unprocessable) {
                    $unprocessable[] = "{$fixture->code}: {$document->last_error}";
                }
            }

            if ($dryRun) {
                $this->info("{$fixture->code}: dry-run — документ/версия не созданы.");

                continue;
            }

            $goldPath = "{$goldDir}/{$fixture->code}.gold.json";

            if (is_file($goldPath)) {
                if ($this->importExample($version, $fixture, 'extraction', $goldPath, isScreening: false)) {
                    $extractionCount++;
                } else {
                    $rejected++;
                }
            }

            $screeningPath = "{$goldDir}/{$fixture->code}.screening.json";

            if (is_file($screeningPath)) {
                if ($this->importExample($version, $fixture, 'screening', $screeningPath, isScreening: true)) {
                    $screeningCount++;
                } else {
                    $rejected++;
                }
            }
        }

        foreach ($unprocessable as $line) {
            $this->warn("unprocessable: {$line}");
        }

        $this->info(
            "Готово: документов {$documentsCount} · версий {$versionsCount} · "
            ."записей extraction {$extractionCount} · записей screening {$screeningCount} · "
            ."отклонено {$rejected} · unprocessable ".count($unprocessable).'.',
        );

        return $rejected > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: Document, 1: DocumentVersion, 2: bool, 3: bool}
     */
    private function resolveWorkingCopy(Fixture $fixture, string $fixturesDisk, string $documentsDisk, bool $dryRun): array
    {
        $externalId = "fixture:{$fixture->code}";
        $existingDocument = $this->documents->findByExternalId(null, $externalId);
        $isNewDocument = $existingDocument === null;

        if ($dryRun) {
            // dry-run не создаёт ничего — возвращаем несохранённые in-memory объекты только для
            // единообразия сигнатуры вызывающего кода (их id/атрибуты дальше не используются).
            return [$existingDocument ?? new Document, new DocumentVersion, $isNewDocument, $isNewDocument];
        }

        $document = $existingDocument ?? $this->documents->create([
            'procurement_id' => null,
            'external_id' => $externalId,
            'title' => $fixture->original_filename,
            'kind' => null,
            'source_url' => null,
            'status' => DocumentStatus::Downloaded,
        ]);

        $existingVersion = $this->documents->findVersionByHash($document->id, $fixture->sha256);
        $isNewVersion = $existingVersion === null;

        if ($existingVersion !== null) {
            return [$document, $existingVersion, $isNewDocument, false];
        }

        $storagePath = 'documents/'.substr($fixture->sha256, 0, 2)."/{$fixture->sha256}";

        if (! Storage::disk($documentsDisk)->exists($storagePath)) {
            $stream = Storage::disk($fixturesDisk)->readStream($fixture->storage_path);
            Storage::disk($documentsDisk)->put($storagePath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $version = $this->documents->createVersion([
            'document_id' => $document->id,
            'content_hash' => $fixture->sha256,
            'size_bytes' => $fixture->size_bytes,
            'filename' => $fixture->original_filename,
            'extension' => $fixture->source_format,
            'mime' => $fixture->mime,
            'storage_path' => $storagePath,
            'downloaded_at' => now(),
        ]);

        return [$document, $version, $isNewDocument, $isNewVersion];
    }

    private function importExample(DocumentVersion $version, Fixture $fixture, string $task, string $path, bool $isScreening): bool
    {
        $decoded = json_decode((string) file_get_contents($path), associative: true);

        if (! is_array($decoded)) {
            $this->error("{$fixture->code}: {$path} не парсится как JSON-объект");

            return false;
        }

        if ($isScreening) {
            try {
                ScreeningResult::validateAndCreate($decoded);
            } catch (ValidationException|CannotCreateData $e) {
                $this->error("{$fixture->code}: screening-gold не прошёл валидацию: {$e->getMessage()}");

                return false;
            }
        } else {
            $errors = $this->goldValidator->validate($decoded);

            if ($errors !== []) {
                $this->error("{$fixture->code}: extraction-gold не прошёл валидацию: ".implode('; ', $errors));

                return false;
            }
        }

        DatasetExample::query()->updateOrCreate(
            [
                'document_version_id' => $version->id,
                'task' => $task,
                'version' => 1,
            ],
            [
                'fixture_id' => $fixture->id,
                'document_type' => $fixture->document_type->value,
                'gold' => $decoded,
                'source' => 'deterministic',
            ],
        );

        return true;
    }
}
