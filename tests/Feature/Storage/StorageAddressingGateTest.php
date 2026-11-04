<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/** Проверяет централизованное разрешение дисков и поимённый allowlist с причинами. */

/**
 * @return array<string, string>
 */
function storageAddressingGateAllowlist(): array
{
    return [
        /** Проверяет централизованное разрешение дисков и поимённый allowlist с причинами. */
        'CsvBlockIngestor.php' => 'prc_fetches.artifact_ref — партиционированный носитель без второго поколения (research/07 §2)',
        'CsvSourceMethod.php' => 'HTML-снапшоты без записи в БД — адресовать колонкой нечего (research/07 §2)',
        'DatasetExportCommand.php' => 'продукт команды (--disk опция), не персистентная ссылка (research/07 §2)',
        // Следующие файлы используют текущий диск вместо диска из записи.
        // Пишут/читают ТЕКУЩИЙ диск (та же семантика, что current*Disk()), не диск ИЗ ЗАПИСИ —
        // конвертация безопасна, но это отдельный дифф. Pending Work .
        'FormatBenchRunner.php' => 'producePdfAlt() уже пишет реальное имя диска (D115, :440) — вне Files P6.3, follow-up',
        'GoldBuildCommand.php' => 'estimate:gold:build читает текущий диск эталонов для GoldCorpusBuilder — вне Files P6.3, follow-up',
        'FixtureForm.php' => 'Filament upload-виджет — текущий диск для НОВОЙ загрузки — вне Files P6.3, follow-up',
        'FixturesVerifyCommand.php' => 'estimate:fixtures:verify читает текущий диск эталонов — вне Files P6.3, follow-up',
    ];
}

test('вне app/Services/Storage прямое чтение конфиг-ключей диска запрещено', function (): void {
    $allowlist = storageAddressingGateAllowlist();
    $pattern = '/config\(\s*[\'"](crawler\.documents_disk|ai\.fixtures\.disk|crawler\.raw_disk)[\'"]/';
    $violations = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        if (str_starts_with($file->getRelativePathname(), 'Services/Storage/')) {
            continue;
        }

        $basename = $file->getBasename('.php').'.php';

        if (array_key_exists($basename, $allowlist)) {
            continue;
        }

        $source = (string) file_get_contents($file->getRealPath());

        if (preg_match($pattern, $source)) {
            $violations[] = $file->getRelativePathname();
        }
    }

    expect($violations)->toBe([]);
});
