<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * Гейт P6.3 (`research/07-storage-addressing.md` §4.5): запрещает прямое чтение конфиг-ключей
 * диска (`crawler.documents_disk`, `ai.fixtures.disk`, `crawler.raw_disk`) вне
 * `app/Services/Storage/**` — единственные легальные читатели этих ключей ЗАВЕДЕНЫ там
 * (`FileLocationResolver::current*Disk()`). Форма — Finder/grep по дереву (тот же приём, что
 * `tests/Unit/Architecture/DomainLayoutTest.php`), не `pest-plugin-arch`: предмет проверки —
 * строка вызова, а не граф `uses`.
 *
 * Allowlist — поимённый, со строкой-причиной на каждый файл. Расширение без причины в комментарии
 * запрещено (Implementation Rules P6.3).
 */

/**
 * @return array<string, string>
 */
function storageAddressingGateAllowlist(): array
{
    return [
        // Носители с вердиктом «колонка не нужна» (research/07 §2) — адресация конфигом законна.
        'LlmCallRecorder.php' => 'llm_calls.request_ref/response_ref — партиционированный носитель без второго поколения (research/07 §2)',
        'LlmPayloadReader.php' => 'читатель llm_calls.*_ref — тот же вердикт, что у LlmCallRecorder',
        'CsvBlockIngestor.php' => 'prc_fetches.artifact_ref — партиционированный носитель без второго поколения (research/07 §2)',
        'CsvSourceMethod.php' => 'HTML-снапшоты без записи в БД — адресовать колонкой нечего (research/07 §2)',
        'DatasetExportCommand.php' => 'продукт команды (--disk опция), не персистентная ссылка (research/07 §2)',
        // Найдены разведкой P6.3 (§12.4), но вне Files этого item'а — периметр D115 их не назвал.
        // Пишут/читают ТЕКУЩИЙ диск (та же семантика, что current*Disk()), не диск ИЗ ЗАПИСИ —
        // конвертация безопасна, но это отдельный дифф. Pending Work P6.3.
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
