<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Exceptions\Procurement\OperationFailed;
use App\Services\Procurement\Fixture\FixtureManifestReader;
use App\Services\Procurement\Fixture\FixtureRegistrar;
use App\Services\Storage\FileLocationResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Импорт `estimates/manifest.json` (D1/D20, P2.3): читает манифест, валидирует КАЖДУЮ запись
 * контрактом `FixtureManifestEntry`, кладёт файл эталона на текущий диск эталонов
 * ({@see FileLocationResolver::currentFixturesDisk()}), апсертит запись реестра `prc_ai_fixtures`.
 * Идемпотентность — по sha256: повтор не перезаливает файл и не плодит записи; битая запись
 * отклоняется индивидуально, не роняя остальные. Чтение манифеста и импорт записи вынесены в
 * {@see FixtureManifestReader}/{@see FixtureRegistrar} (P3.3).
 */
#[Description('Импорт эталонного корпуса из manifest.json в хранилище и реестр prc_ai_fixtures')]
#[Signature('estimate:fixtures:import
        {--manifest= : путь к manifest.json (дефолт config(ai.fixtures.manifest_path))}
        {--source-dir= : откуда брать файлы, которых нет в хранилище (дефолт config(ai.fixtures.source_dir))}
        {--dry-run : только проверить, ничего не писать}')]
final class FixturesImportCommand extends Command
{
    public function __construct(
        private readonly FixtureManifestReader $manifestReader,
        private readonly FixtureRegistrar $registrar,
        private readonly FileLocationResolver $locationResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $manifestPath = (string) ($this->option('manifest') ?? config('ai.fixtures.manifest_path'));
        $sourceDir = (string) ($this->option('source-dir') ?? config('ai.fixtures.source_dir'));
        $dryRun = (bool) $this->option('dry-run');
        $disk = $this->locationResolver->currentFixturesDisk();

        try {
            $manifest = $this->manifestReader->read($manifestPath);
        } catch (OperationFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $imported = 0;
        $failed = 0;

        foreach ($manifest['fixtures'] as $index => $entryJson) {
            if (! is_array($entryJson)) {
                $this->error("fixtures.{$index}: ожидается объект");
                $failed++;

                continue;
            }

            $errors = $this->registrar->import($entryJson, $index, $disk, $sourceDir, $dryRun);

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
}
