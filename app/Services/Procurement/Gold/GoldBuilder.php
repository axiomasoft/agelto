<?php

declare(strict_types=1);

namespace App\Services\Procurement\Gold;

use App\Services\Procurement\Prepare\Gsfx\GsfxReader;
use App\Services\Procurement\Prepare\Gsfx\GsfxUnreadableException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;
use ZipArchive;

/**
 * Диспетчер сборки gold ОДНОГО эталона (P5.3, D45) по `source_format`: `.gsfx` →
 * {@see GsfxGoldMapper} (через {@see GsfxReader}, тот же приём извлечения контейнера, что
 * `GsfxStrategy` P3.3), `xlsx`/`xls` → {@see SheetGoldMapper} по профилю формы (P5.2, D117), тот же
 * алгоритм гейта/усреднения, что зонд P5.7 (`artifacts/P5.7-profile-run.php`) — команда и зонд
 * обязаны видеть один и тот же вердикт. Диспетчер — `match`, не реестр стратегий (Code Guidance
 * P5.3): мапперов ровно два. Без записи на диск — это работа `GoldBuildCommand`.
 */
final readonly class GoldBuilder
{
    public function __construct(
        private FormProfileReader $profileReader,
        private GridRecognitionGate $gate,
        private SheetGoldMapper $sheetMapper,
        private GsfxGoldMapper $gsfxMapper,
        private GsfxReader $gsfxReader,
        private GoldValidator $validator,
    ) {}

    /**
     * @return array{
     *     action: 'built'|'downgraded'|'rejected',
     *     gold: array<string, mixed>|null,
     *     rows: int|null,
     *     estimates: int|null,
     *     arithmetic_ratio: float|null,
     *     unmapped: array<string, array{count: int, has_carrier: bool}>,
     *     reason: string|null,
     * }
     */
    public function build(string $sourceFormat, string $sourcePath, ?string $profilePath): array
    {
        return match ($sourceFormat) {
            'gsfx' => $this->buildFromGsfx($sourcePath),
            'xlsx', 'xls' => $this->buildFromSheet($sourcePath, $profilePath),
            default => $this->rejected("неизвестный source_format для детерминированного gold: {$sourceFormat}"),
        };
    }

    /**
     * @return array{action: 'built'|'rejected', gold: array<string, mixed>|null, rows: int|null,
     *     estimates: int|null, arithmetic_ratio: float|null,
     *     unmapped: array<string, array{count: int, has_carrier: bool}>, reason: string|null}
     */
    private function buildFromGsfx(string $path): array
    {
        if (! is_file($path)) {
            return $this->rejected("файл эталона не найден: {$path}");
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return $this->rejected('не удалось открыть контейнер как ZIP');
        }

        $dataXml = $zip->getFromName('Data.xml');

        if ($dataXml === false) {
            $zip->close();

            return $this->rejected('в контейнере отсутствует Data.xml');
        }

        $propertiesTxt = $zip->getFromName('Properties.txt');
        $zip->close();

        try {
            $document = $this->gsfxReader->parse((string) ($propertiesTxt !== false ? $propertiesTxt : ''), $dataXml);
        } catch (GsfxUnreadableException $e) {
            return $this->rejected($e->getMessage());
        }

        $gold = $this->gsfxMapper->toGold($document);
        $errors = $this->validator->validate($gold);

        if ($errors !== []) {
            return $this->rejected('GoldValidator: '.implode('; ', $errors));
        }

        return [
            'action' => 'built',
            'gold' => $gold,
            'rows' => $this->countRows($gold),
            'estimates' => count($gold['estimates']),
            'arithmetic_ratio' => null,
            'unmapped' => $this->gsfxMapper->unmappedReport($document),
            'reason' => null,
        ];
    }

    /**
     * @return array{action: 'built'|'downgraded'|'rejected', gold: array<string, mixed>|null,
     *     rows: int|null, estimates: int|null, arithmetic_ratio: float|null,
     *     unmapped: array<string, array{count: int, has_carrier: bool}>, reason: string|null}
     */
    private function buildFromSheet(string $sourcePath, ?string $profilePath): array
    {
        if ($profilePath === null || ! is_file($profilePath)) {
            return $this->rejected('профиль формы не найден: '.($profilePath ?? '(не задан)'));
        }

        if (! is_file($sourcePath)) {
            return $this->rejected("файл эталона не найден: {$sourcePath}");
        }

        try {
            $profile = $this->profileReader->read($profilePath);
        } catch (Throwable $e) {
            return $this->rejected("профиль отклонён: {$e->getMessage()}");
        }

        $spreadsheet = IOFactory::load($sourcePath);
        $evaluations = [];
        $failedConditions = [];

        foreach ($profile->estimates as $estimate) {
            $sheet = $spreadsheet->getSheetByName($estimate->sheet);

            if ($sheet === null) {
                $evaluations[] = null;
                $failedConditions[] = "лист «{$estimate->sheet}» не найден";

                continue;
            }

            $result = $this->gate->evaluate($sheet, $estimate);
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

        if (! $allPassed) {
            return $this->downgraded(implode('; ', $failedConditions), count($profile->estimates));
        }

        try {
            $gold = $this->sheetMapper->toGold($spreadsheet, $profile);
        } catch (ValidationException $e) {
            return $this->downgraded('GoldValidator: '.implode('; ', Arr::flatten($e->errors())), count($profile->estimates));
        }

        return [
            'action' => 'built',
            'gold' => $gold,
            'rows' => $this->countRows($gold),
            'estimates' => count($gold['estimates']),
            'arithmetic_ratio' => $this->simpleAverage($validEvaluations, 'arithmetic_match_ratio'),
            'unmapped' => [],
            'reason' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $gold
     */
    private function countRows(array $gold): int
    {
        return array_sum(array_map(
            static fn (array $estimate): int => count($estimate['rows'] ?? []),
            $gold['estimates'],
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $evaluations
     */
    private function simpleAverage(array $evaluations, string $field): ?float
    {
        $values = array_values(array_filter(
            array_column($evaluations, $field),
            static fn (?float $v): bool => $v !== null,
        ));

        return $values === [] ? null : array_sum($values) / count($values);
    }

    /**
     * @return array{action: 'downgraded', gold: null, rows: null, estimates: int, arithmetic_ratio: null,
     *     unmapped: array<never>, reason: string}
     */
    private function downgraded(string $reason, int $estimatesCount): array
    {
        return [
            'action' => 'downgraded',
            'gold' => null,
            'rows' => null,
            'estimates' => $estimatesCount,
            'arithmetic_ratio' => null,
            'unmapped' => [],
            'reason' => $reason,
        ];
    }

    /**
     * @return array{action: 'rejected', gold: null, rows: null, estimates: null, arithmetic_ratio: null,
     *     unmapped: array<never>, reason: string}
     */
    private function rejected(string $reason): array
    {
        return [
            'action' => 'rejected',
            'gold' => null,
            'rows' => null,
            'estimates' => null,
            'arithmetic_ratio' => null,
            'unmapped' => [],
            'reason' => $reason,
        ];
    }
}
