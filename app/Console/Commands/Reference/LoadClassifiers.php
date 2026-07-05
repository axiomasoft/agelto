<?php

declare(strict_types=1);

namespace App\Console\Commands\Reference;

use App\Services\Reference\ClassifierImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Наполняет справочники `ref_*` (ОКПД2/КТРУ/ОКВЭД2/КБК) из официальной выгрузки —
 * идемпотентный upsert по `code`. Путь выгрузки — из `config('reference.classifiers')`;
 * production-наполнение реальными файлами — операторская задача (D16), команда лишь
 * предупреждает и пропускает классификатор без сконфигурированного/существующего пути.
 */
final class LoadClassifiers extends Command
{
    protected $signature = 'ref:load-classifiers {classifier? : okpd2|ktru|okved2|kbk — по умолчанию все}';

    protected $description = 'Наполняет справочники ref_* из официальной выгрузки (идемпотентный upsert по code)';

    public function handle(ClassifierImporter $importer): int
    {
        $classifiers = $this->resolveClassifiers();

        if ($classifiers->isEmpty()) {
            $this->error('Неизвестный классификатор.');

            return self::FAILURE;
        }

        foreach ($classifiers as $key => $config) {
            $this->importClassifier(importer: $importer, key: $key, config: $config);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{model: class-string, source_path: string|null}  $config
     */
    private function importClassifier(ClassifierImporter $importer, string $key, array $config): void
    {
        $path = $config['source_path'];

        if ($path === null || $path === '') {
            $this->warn("Классификатор «{$key}»: путь выгрузки не сконфигурирован, пропуск.");

            return;
        }

        if (! is_file($path)) {
            $this->warn("Классификатор «{$key}»: файл выгрузки не найден ({$path}), пропуск.");

            return;
        }

        $modelClass = $config['model'];
        $columns = $this->columnsFor(key: $key);
        $chunkSize = (int) config('reference.import_chunk_size', 500);
        $imported = 0;

        LazyCollection::make(fn () => $importer->rows(path: $path))
            ->chunk($chunkSize)
            ->each(function (LazyCollection $chunk) use ($modelClass, $columns, &$imported): void {
                $now = now();

                $rows = $chunk
                    ->map(fn (array $row) => [
                        ...array_intersect_key($row, array_flip($columns)),
                        'attributes' => $row['attributes'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])
                    ->all();

                if ($rows === []) {
                    return;
                }

                $modelClass::query()->upsert(
                    values: $rows,
                    uniqueBy: ['code'],
                    update: [...array_diff($columns, ['code']), 'attributes', 'updated_at'],
                );

                $imported += count($rows);
            });

        $this->info("Классификатор «{$key}»: обработано {$imported} строк.");
    }

    /**
     * @return list<string>
     */
    private function columnsFor(string $key): array
    {
        $base = ['code', 'parent_code', 'level', 'name', 'valid_from', 'valid_to'];

        return $key === 'ktru' ? [...$base, 'okpd2_code'] : $base;
    }

    /**
     * @return Collection<string, array{model: class-string, source_path: string|null}>
     */
    private function resolveClassifiers(): Collection
    {
        /** @var Collection<string, array{model: class-string, source_path: string|null}> $all */
        $all = collect(config('reference.classifiers', []));
        $requested = $this->argument('classifier');

        if ($requested === null) {
            return $all;
        }

        return $all->only([$requested]);
    }
}
