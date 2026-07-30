<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Enums\Procurement\AiDocumentType;
use App\Services\Procurement\Analyzers\Estimate\DatasetImporter;
use Illuminate\Console\Command;

/**
 * Импорт папки эталонных смет (P7.4, ТЗ 04 §5): пары `<name>.<ext>` (исходный файл — pdf/xlsx/
 * скан) + `<name>.gold.json` (эталонный разбор, формат — `docs/specs/v2/08-estimate-extraction.md`
 * §8). Каждый эталон валидируется контрактом P2.2 (`PassportResponse`/`EstimateRow`) ДО записи —
 * битый JSON отклоняется с перечнем ошибок, остальные пары импорта не затрагивает. Валидация и
 * запись пары вынесены в {@see DatasetImporter} (P3.3).
 */
final class DatasetImportCommand extends Command
{
    protected $signature = 'estimate:dataset:import
        {path : путь к папке с парами <name>.<ext> + <name>.gold.json}
        {--task=extraction : значение prc_ai_dataset_examples.task}
        {--source=manual : значение prc_ai_dataset_examples.source}
        {--document-type=estimate : значение prc_ai_dataset_examples.document_type (AiDocumentType, D16/D17) — папка вне реестра prc_ai_fixtures не несёт собственного носителя оси}
        {--gold-version=1 : версия эталона (upsert-ключ вместе с document_version_id+task; `--version` зарезервирован Symfony Console)}';

    protected $description = 'Импорт папки эталонных смет в prc_ai_dataset_examples (gold, P2.2-контракт)';

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
                $this->error("{$baseName}: gold-эталон не прошёл валидацию контракта P2.2:");
                foreach ($validation['errors'] as $error) {
                    $this->line("  - {$error}");
                }
                $failed++;

                continue;
            }

            $this->importer->importPair(
                $baseName,
                $sourceFile,
                $validation['normalized'],
                $documentType,
                (string) $this->option('task'),
                (string) $this->option('source'),
                (int) $this->option('gold-version'),
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
