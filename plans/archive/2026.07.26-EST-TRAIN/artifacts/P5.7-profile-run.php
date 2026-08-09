<?php

/**
 * P5.7 — прогон гейта распознаваемости по девяти профилям табличных эталонов первой волны
 * (Implementation Rules P5.7). Единственный путь прогона: манифест → профиль → GridRecognitionGate
 * по КАЖДОМУ estimates[] книги → при pass by profile ВСЕХ estimates[] книги — SheetGoldMapper::
 * toGold() + GoldValidator. Файлов gold НЕ пишет (D91 — указатель и файл появляются одним актом
 * в P5.3). Печатает TSV: код · смет · вердикт · условие а/б/в/г · покрытие ролей · доля чисел ·
 * строк item · доля сходимости · gold валиден.
 *
 * Запуск: docker exec agelto_app php artisan tinker --execute="require base_path('plans/2026.07.26-EST-TRAIN/artifacts/P5.7-profile-run.php');"
 */

use App\Services\Procurement\Gold\FormProfileReader;
use App\Services\Procurement\Gold\GoldValidator;
use App\Services\Procurement\Gold\GridRecognitionGate;
use App\Services\Procurement\Gold\SheetGoldMapper;
use PhpOffice\PhpSpreadsheet\IOFactory;

ini_set('memory_limit', '1024M');

$manifest = json_decode(file_get_contents(base_path('estimates/manifest.json')), true);
$fixtures = array_values(array_filter(
    $manifest['fixtures'],
    static fn (array $f): bool => $f['class'] === 'est' && in_array($f['source_format'], ['xlsx', 'xls'], true),
));

$reader = new FormProfileReader;
$gate = new GridRecognitionGate;
$validator = new GoldValidator;
$mapper = new SheetGoldMapper($gate, $validator);

$rows = [];

echo "код\tкластер\tсмет\tвердикт\tусловие а\tпокрытие ролей\tдоля чисел\tстрок item\tдоля сходимости\tgold валиден\tпричина\n";

foreach ($fixtures as $fixture) {
    $code = $fixture['code'];
    $profilePath = base_path("estimates/gold/{$code}.profile.json");

    if (! is_file($profilePath)) {
        echo "{$code}\t{$fixture['cluster_id']}\t—\tMISSING PROFILE\t—\t—\t—\t—\t—\t—\tпрофиль не найден\n";

        continue;
    }

    try {
        $profile = $reader->read($profilePath);
    } catch (Throwable $e) {
        echo "{$code}\t{$fixture['cluster_id']}\t—\tprofile rejected\t—\t—\t—\t—\t—\t—\t{$e->getMessage()}\n";

        continue;
    }

    $spreadsheetPath = storage_path('app/private/estimates/'.$fixture['storage_path']);
    $spreadsheet = IOFactory::load($spreadsheetPath);

    $evaluations = [];
    $failedConditions = [];

    foreach ($profile->estimates as $estimate) {
        $sheet = $spreadsheet->getSheetByName($estimate->sheet);

        if ($sheet === null) {
            $evaluations[] = null;
            $failedConditions[] = "лист «{$estimate->sheet}» не найден";

            continue;
        }

        $result = $gate->evaluate($sheet, $estimate);
        $evaluations[] = $result;

        if (! $result['passed']) {
            $flags = [];
            foreach (['a', 'b', 'c', 'd'] as $letter) {
                if (! $result["condition_{$letter}"]) {
                    $flags[] = $letter;
                }
            }
            $failedConditions[] = "estimate {$estimate->ordinal}: провалены условия ".implode(',', $flags);
        }
    }

    $validEvaluations = array_values(array_filter($evaluations));
    $allPassed = $evaluations !== [] && count($validEvaluations) === count($evaluations)
        && array_reduce($validEvaluations, static fn (bool $carry, array $r): bool => $carry && $r['passed'], true);

    $conditionA = $validEvaluations === [] ? false : array_reduce($validEvaluations, static fn (bool $c, array $r): bool => $c && $r['condition_a'], true);
    $totalItemRows = array_sum(array_column($validEvaluations, 'item_row_count'));

    $roleCoverage = simpleAverage($validEvaluations, 'role_coverage_ratio');
    $quantityRatio = simpleAverage($validEvaluations, 'quantity_numeric_ratio');
    $arithmeticRatio = simpleAverage($validEvaluations, 'arithmetic_match_ratio');

    $goldValid = null;
    $goldError = null;

    if ($allPassed) {
        try {
            $mapper->toGold($spreadsheet, $profile);
            $goldValid = true;
        } catch (Throwable $e) {
            $goldValid = false;
            $goldError = $e->getMessage();
        }
    }

    $verdict = ($allPassed && $goldValid !== false) ? 'pass by profile' : 'downgrade';
    $reason = match (true) {
        ! $allPassed => implode('; ', $failedConditions),
        $goldValid === false => "GoldValidator: {$goldError}",
        default => '—',
    };

    printf(
        "%s\t%s\t%d\t%s\t%s\t%.4f\t%.4f\t%d\t%s\t%s\t%s\n",
        $code,
        $fixture['cluster_id'],
        count($profile->estimates),
        $verdict,
        $conditionA ? '100%' : 'fail',
        $roleCoverage,
        $quantityRatio,
        $totalItemRows,
        $arithmeticRatio === null ? 'n/a' : sprintf('%.4f', $arithmeticRatio),
        $allPassed ? ($goldValid ? 'да' : 'нет') : '—',
        $reason,
    );

    $rows[$code] = compact('verdict', 'evaluations');
}

/**
 * Простое среднее по estimates[] книги (не взвешенное числом строк) — книга с одним `estimates[]`
 * (8 из 9 эталонов) даёт то же число, что и единственная оценка; книга с несколькими (LSR-002,
 * шесть смет) — среднее по сметам, а не по строкам, той же точности отчёту достаточно.
 */
function simpleAverage(array $evaluations, string $field): ?float
{
    $values = array_values(array_filter(array_column($evaluations, $field), static fn (?float $v): bool => $v !== null));

    return $values === [] ? null : array_sum($values) / count($values);
}
