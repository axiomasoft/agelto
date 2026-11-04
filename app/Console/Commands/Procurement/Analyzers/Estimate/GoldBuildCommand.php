<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Exceptions\Procurement\OperationFailed;
use App\Repositories\Procurement\Ai\FixtureStoreRepository;
use App\Services\Procurement\Fixture\FixtureManifestReader;
use App\Services\Procurement\Fixture\FixtureManifestWriter;
use App\Services\Procurement\Gold\GoldCorpusBuilder;
use App\Services\Procurement\Gold\ScreeningGoldBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Сборка `estimates/gold/**` по реестру `prc_ai_fixtures`: для каждого эталона с
 * `gold_source=deterministic` вызывает {@see GoldCorpusBuilder} для `.gsfx` или книги, пишет `<CODE>.gold.json`, собирает сводный отчёт и, под `--update-manifest`, дописывает в
 * `estimates/manifest.json` РОВНО четыре gold-производных поля. Команда — сборщик, а не редактор
 * истины: манифест остаётся SSOT корпуса.
 *
 * Ветка `--screening` (§8.5) — ОТДЕЛЬНЫЙ проход по ВСЕМ эталонам корпуса (не только
 * `gold_source=deterministic`) через {@see ScreeningGoldBuilder}: пишет `<CODE>.screening.json`
 * РЯДОМ с extraction-gold, манифест НЕ трогает (screening-gold в манифест не заводится, §8.5).
 */
#[Description('Собрать estimates/gold/** по реестру prc_ai_fixtures и обновить gold-факты манифеста')]
#[Signature('estimate:gold:build
        {--code=* : ограничить эталоны кодами}
        {--gold-dir= : куда писать (дефолт config(ai.fixtures.gold_dir))}
        {--manifest= : путь к manifest.json}
        {--update-manifest : дописать gold-производные факты в манифест}
        {--dry-run : ничего не писать, только отчёт}
        {--screening : собрать gold/<CODE>.screening.json по всему корпусу вместо extraction-gold}')]
final class GoldBuildCommand extends Command
{
    public function __construct(
        private readonly FixtureStoreRepository $fixtures,
        private readonly FixtureManifestReader $manifestReader,
        private readonly FixtureManifestWriter $manifestWriter,
        private readonly GoldCorpusBuilder $corpusBuilder,
        private readonly ScreeningGoldBuilder $screeningBuilder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Книги реального корпуса (десятки листов, MERGE_HEAVY) исчерпывают дефолтный лимит
        // PHP-CLI на PhpSpreadsheet::load — тот же приём, что `SpreadsheetStrategy`
        // применяет к дочернему процессу конвертации.
        ini_set('memory_limit', $this->configString('ai.prepare.xlsx_convert_memory_limit', default: '512M'));

        $goldDir = $this->optionOrConfigString(option: 'gold-dir', configKey: 'ai.fixtures.gold_dir');
        $dryRun = (bool) $this->option('dry-run');
        /** @var list<string> $onlyCodes */
        $onlyCodes = (array) $this->option('code');

        if ((bool) $this->option('screening')) {
            return $this->handleScreening($goldDir, $onlyCodes, $dryRun);
        }

        $manifestPath = $this->optionOrConfigString(option: 'manifest', configKey: 'ai.fixtures.manifest_path');
        $updateManifest = (bool) $this->option('update-manifest');

        try {
            $manifest = $this->manifestReader->read($manifestPath);
        } catch (OperationFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $fixtures = $this->fixtures->inCodeOrder($onlyCodes);
        $result = $this->corpusBuilder->build($fixtures, $goldDir, $this->configString('ai.fixtures.disk'), $dryRun, $manifest['fixtures']);

        if ($updateManifest && ! $dryRun) {
            $manifest['fixtures'] = $result['manifestFixtures'];
            $this->manifestWriter->write($manifestPath, $manifest);
        }

        if (! $dryRun) {
            // Каталог отчётов создаётся при записи:
            // `estimates/reports/` в репозитории не существует.
            $reportPath = $this->configString('ai.fixtures.reports_dir').'/gold-corpus-report.md';
            File::ensureDirectoryExists(dirname($reportPath));
            file_put_contents($reportPath, $this->corpusBuilder->renderReport($result['rows']));
        }

        $counts = $result['counts'];
        $this->info("Готово: построено {$counts['built']} · пропущено {$counts['skipped']} · понижено {$counts['downgraded']} · отклонено {$counts['rejected']}.");

        return $counts['rejected'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<string>  $onlyCodes
     */
    private function handleScreening(string $goldDir, array $onlyCodes, bool $dryRun): int
    {
        $fixtures = $this->fixtures->inCodeOrder($onlyCodes);

        $built = 0;
        $rejected = 0;

        foreach ($fixtures as $fixture) {
            $result = $this->screeningBuilder->build($fixture);

            if ($result['action'] === 'rejected') {
                $rejected++;
                $this->error("{$fixture->code}: {$result['reason']}");

                continue;
            }

            $built++;

            if (! $dryRun) {
                file_put_contents(
                    "{$goldDir}/{$fixture->code}.screening.json",
                    // `JSON_PRESERVE_ZERO_FRACTION` — `confidence` эталона всегда `1.0` (float,
                    // §8.5), без флага PHP печатает целое `1` и `ScreeningResult::
                    // validateAndCreate` на повторном чтении расходится по типу.
                    json_encode($result['screening'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n",
                );
            }
        }

        $this->info("Готово: построено {$built} · отклонено {$rejected}.");

        return $rejected > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function optionOrConfigString(string $option, string $configKey): string
    {
        $optionValue = $this->option($option);

        if (is_string($optionValue) && $optionValue !== '') {
            return $optionValue;
        }

        return $this->configString($configKey);
    }

    private function configString(string $key, ?string $default = null): string
    {
        $value = config($key, $default);

        if (! is_string($value) || $value === '') {
            throw new \UnexpectedValueException("{$key} must be a non-empty string.");
        }

        return $value;
    }
}
