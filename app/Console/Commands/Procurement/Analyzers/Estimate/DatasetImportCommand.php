<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Services\Procurement\Analyzers\Estimate\Eval\DatasetImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Импорт папки эталонных смет (ТЗ 04 §5): пары `<name>.<ext>` (исходный файл — pdf/xlsx/
 * скан) + `<name>.gold.json` (эталонный разбор, формат — `docs/specs/v2/08-estimate-extraction.md`
 * §8). Каждый эталон валидируется контрактом  (`PassportResponse`/`EstimateRow`) ДО записи —
 * битый JSON отклоняется с перечнем ошибок, остальные пары импорта не затрагивает. Валидация и
 * запись пары вынесены в {@see DatasetImporter}.
 */
#[Description('Импорт папки эталонных смет в prc_ai_dataset_examples с валидацией gold')]
#[Signature('estimate:dataset:import
        {path : путь к папке с парами <name>.<ext> + <name>.gold.json}
        {--task=extraction : значение prc_ai_dataset_examples.task}
        {--source=manual : значение prc_ai_dataset_examples.source}
        {--verified : отметить импортируемые эталоны как проверенные}
        {--document-type=estimate : тип документа из AiDocumentType для эталонов вне реестра prc_ai_fixtures}
        {--gold-version=1 : версия эталона (upsert-ключ вместе с document_version_id+task; `--version` зарезервирован Symfony Console)}')]
final class DatasetImportCommand extends Command
{
    public function __construct(private readonly DatasetImporter $importer)
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

        $documentType = AiDocumentType::tryFrom((string) $this->option('document-type'));

        if ($documentType === null) {
            $this->error("Неизвестный --document-type={$this->option('document-type')}.");

            return self::FAILURE;
        }

        $verified = (bool) $this->option('verified');
        if (! $verified) {
            $this->warn('Импорт без --verified сохраняет эталоны без отметки проверки.');
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

            $validation = $this->importer->validateGold((string) file_get_contents($goldPath));

            if ($validation['errors'] !== []) {
                $this->error("{$baseName}: gold-эталон не прошёл валидацию контракта:");
                foreach ($validation['errors'] as $error) {
                    $this->line("  - {$error}");
                }
                $failed++;

                continue;
            }

            $normalizedGold = $validation['normalized'];

            if (! is_array($normalizedGold)) {
                $this->error("{$baseName}: валидатор не вернул нормализованный gold-эталон.");
                $failed++;

                continue;
            }

            /** @var array<string, mixed> $normalizedGold */
            $this->importer->importPair(
                baseName: $baseName,
                sourceFile: $sourceFile,
                normalizedGold: $normalizedGold,
                documentType: $documentType,
                task: (string) $this->option('task'),
                source: (string) $this->option('source'),
                goldVersion: (int) $this->option('gold-version'),
                verified: $verified,
            );
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
}
