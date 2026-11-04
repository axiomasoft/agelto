<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Services\Procurement\Fixture\FixtureManifestBuilder;
use App\Services\Procurement\Fixture\FixtureManifestWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;

/**
 * Генератор `estimates/manifest.json` из шорт-листа эталонов: `storage_path`
 * пересчитывается построителем `Fixture::storagePathFor`, `sha256`/`size_bytes` берутся из
 * шорт-листа и ПРОВЕРЯЮТСЯ пересчётом по файлу-источнику (расхождение — ошибка, запись не
 * добавляется), `mime` — из инвентаря  (при отсутствии — `finfo` по файлу), `gold_path` —
 * всегда `null` (указатель на файл gold пишет его производитель — `estimate:gold:build
 * --update-manifest`,  — вместе с самим файлом; намерение несёт `gold_source`). Запись, чей
 * `code` уже есть в манифесте, НЕ трогается без `--force` — манифест SSOT и может быть поправлен
 * руками. Построение записей вынесено в {@see FixtureManifestBuilder}.
 */
#[Description('Собрать estimates/manifest.json из шорт-листа эталонов')]
#[Signature('estimate:fixtures:manifest
        {--from-shortlist= : путь к fixture-shortlist.json}
        {--inventory= : путь к JSON-инвентарю корпуса для mime/размера}
        {--manifest= : куда писать (дефолт config(ai.fixtures.manifest_path))}
        {--force : перезаписать существующие записи}')]
final class FixturesManifestCommand extends Command
{
    public function __construct(
        private readonly FixtureManifestBuilder $manifestBuilder,
        private readonly FixtureManifestWriter $manifestWriter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $shortlistPath = $this->requiredOption('from-shortlist');
        $manifestPath = $this->manifestPath();
        $force = (bool) $this->option('force');

        if (! is_file($shortlistPath)) {
            $this->error("Шорт-лист не найден: {$shortlistPath}");

            return self::FAILURE;
        }

        try {
            $shortlist = json_decode((string) file_get_contents($shortlistPath), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("Шорт-лист не распарсен: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! is_array($shortlist) || ! array_is_list($shortlist)) {
            $this->error('Ожидается JSON-список записей шорт-листа.');

            return self::FAILURE;
        }

        $mimeByPath = $this->manifestBuilder->mimeIndex((string) $this->option('inventory'));
        $existing = $this->manifestBuilder->readExistingManifest($manifestPath);

        /** @var list<mixed> $shortlist */
        $result = $this->manifestBuilder->build($shortlist, $existing, $this->sourceDirectory(), $mimeByPath, $force);

        if ($result['errors'] !== []) {
            foreach ($result['errors'] as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $records = $result['records'];
        ksort($records);

        $this->manifestWriter->write($manifestPath, ['version' => 1, 'fixtures' => array_values($records)]);
        $this->info('Готово: '.count($records).' запись(ей) в манифесте.');

        return self::SUCCESS;
    }

    private function requiredOption(string $name): string
    {
        $value = $this->option($name);

        if (! is_string($value) || $value === '') {
            throw new \UnexpectedValueException("--{$name} must be a non-empty string.");
        }

        return $value;
    }

    private function manifestPath(): string
    {
        $value = $this->option('manifest');

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $this->configString('ai.fixtures.manifest_path');
    }

    private function sourceDirectory(): string
    {
        return $this->configString('ai.fixtures.source_dir');
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
