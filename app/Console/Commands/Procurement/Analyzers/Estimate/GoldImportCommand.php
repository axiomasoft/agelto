<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Enums\Procurement\Document\DocumentStatus;
use App\Repositories\Procurement\Ai\FixtureStoreRepository;
use App\Services\Procurement\Fixture\GoldExampleImporter;
use App\Services\Procurement\Fixture\WorkingCopyProvisioner;
use App\Services\Procurement\Prepare\DocumentPreparer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Рабочие копии эталонов + записи датасета: по реестру `prc_ai_fixtures` создаёт
 * `Document`/`DocumentVersion` ВНЕ закупки (`procurement_id=null`), под `--prepare` прогоняет
 * `DocumentPreparer` синхронно и записывает эталонные ОТВЕТЫ
 * `estimates/gold/<CODE>.gold.json`/`<CODE>.screening.json` в `prc_ai_dataset_examples` со
 * ссылкой `fixture_id` и `source=deterministic`. Курируемый оригинал (диск эталонов) и рабочая
 * копия (`documents.disk`) — сознательно РАЗНЫЕ носители: эта команда создаёт только
 * вторую, эталон на диске эталонов не трогает. Резолв рабочей копии и импорт примера вынесены в
 * {@see WorkingCopyProvisioner}/{@see GoldExampleImporter}.
 */
#[Description('Создать рабочие копии эталонов и записи prc_ai_dataset_examples из gold-файлов корпуса')]
#[AsCommand(name: 'estimate:gold:import')]
#[Signature('estimate:gold:import
        {--code=* : ограничить эталоны кодами}
        {--prepare : прогнать цепочку подготовки по созданным версиям}
        {--dry-run : ничего не писать}')]
final class GoldImportCommand extends Command
{
    public function __construct(
        private readonly FixtureStoreRepository $fixtures,
        private readonly WorkingCopyProvisioner $provisioner,
        private readonly GoldExampleImporter $importer,
        private readonly DocumentPreparer $preparer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // `--prepare` прогоняет `SpreadsheetStrategy`/PhpSpreadsheet СИНХРОННО в этом же процессе
        // (не в дочернем, как `ConvertXlsxToPdfCommand`) — текстовая сетка на MERGE_HEAVY книгах
        // корпуса требует больше, чем дефолт xlsx-конвертации (диагностический импорт использовал 1024M).
        ini_set('memory_limit', $this->configString('ai.prepare.gold_import_memory_limit'));

        $goldDir = $this->configString('ai.fixtures.gold_dir');
        $dryRun = (bool) $this->option('dry-run');
        $prepare = (bool) $this->option('prepare');
        /** @var list<string> $onlyCodes */
        $onlyCodes = (array) $this->option('code');

        $fixtures = $this->fixtures->inCodeOrder($onlyCodes);

        $documentsCount = 0;
        $versionsCount = 0;
        $extractionCount = 0;
        $screeningCount = 0;
        $rejected = 0;
        $unprocessable = [];

        foreach ($fixtures as $fixture) {
            [$document, $version, $isNewDocument, $isNewVersion] = $this->provisioner->provision($fixture, $dryRun);

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
                $outcome = $this->importer->import($version, $fixture, 'extraction', $goldPath, isScreening: false);

                if ($outcome->isRejected()) {
                    $this->error("{$outcome->code}: {$outcome->reason}");
                    $rejected++;
                } else {
                    $extractionCount++;
                }
            }

            $screeningPath = "{$goldDir}/{$fixture->code}.screening.json";

            if (is_file($screeningPath)) {
                $outcome = $this->importer->import($version, $fixture, 'screening', $screeningPath, isScreening: true);

                if ($outcome->isRejected()) {
                    $this->error("{$outcome->code}: {$outcome->reason}");
                    $rejected++;
                } else {
                    $screeningCount++;
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

    private function configString(string $key): string
    {
        $value = config($key);

        if (! is_string($value) || $value === '') {
            throw new \UnexpectedValueException("{$key} must be a non-empty string.");
        }

        return $value;
    }
}
