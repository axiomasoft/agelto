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
 * Сборка gold одного эталона по source_format: gsfx через GsfxGoldMapper/GsfxReader,
 * xlsx/xls через SheetGoldMapper и профиль формы. Мапперов два, выбор задаётся match.
 * На диск результат пишет caller GoldBuildCommand.
 */
/**
 * @phpstan-type GoldPayload array{estimates: list<array<string, mixed>>}
 * @phpstan-type UnmappedReport array<string, array{count: int, has_carrier: bool}>
 * @phpstan-type BuiltResult array{action: 'built', gold: GoldPayload, rows: int, estimates: int, arithmetic_ratio: float|null, unmapped: UnmappedReport, reason: null}
 * @phpstan-type DowngradedResult array{action: 'downgraded', gold: null, rows: null, estimates: int, arithmetic_ratio: null, unmapped: UnmappedReport, reason: string}
 * @phpstan-type RejectedResult array{action: 'rejected', gold: null, rows: null, estimates: null, arithmetic_ratio: null, unmapped: UnmappedReport, reason: string}
 * @phpstan-type BuildResult BuiltResult|DowngradedResult|RejectedResult
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
     * @return BuildResult
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
     * @return BuiltResult|RejectedResult
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

        $gold = $this->goldPayload($this->gsfxMapper->toGold($document));
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
     * @return BuildResult
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

        try {
            $spreadsheet = IOFactory::load($sourcePath);
        } catch (Throwable $e) {
            return $this->rejected("не удалось прочитать книгу: {$e->getMessage()}");
        }

        try {
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
                $gold = $this->goldPayload($this->sheetMapper->toGold($spreadsheet, $profile));
            } catch (ValidationException $e) {
                $errors = [];

                foreach (Arr::flatten($e->errors()) as $error) {
                    if (is_string($error)) {
                        $errors[] = $error;
                    }
                }

                return $this->downgraded('GoldValidator: '.implode('; ', $errors), count($profile->estimates));
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
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    /**
     * @param  GoldPayload  $gold
     */
    private function countRows(array $gold): int
    {
        $count = 0;

        foreach ($gold['estimates'] as $estimate) {
            $rows = $estimate['rows'] ?? [];

            if (! is_array($rows)) {
                throw new \LogicException('Строки gold estimate должны быть массивом.');
            }

            $count += count($rows);
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $evaluations
     */
    private function simpleAverage(array $evaluations, string $field): ?float
    {
        $values = [];

        foreach ($evaluations as $evaluation) {
            $value = $evaluation[$field] ?? null;

            if ($value === null) {
                continue;
            }

            if (! is_float($value)) {
                throw new \LogicException("{$field} должен быть float или null.");
            }

            $values[] = $value;
        }

        return $values === [] ? null : array_sum($values) / count($values);
    }

    /**
     * @return DowngradedResult
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
     * @return RejectedResult
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

    /**
     * @return GoldPayload
     */
    private function goldPayload(mixed $value): array
    {
        if (! is_array($value) || ! array_key_exists('estimates', $value) || ! is_array($value['estimates'])) {
            throw new \LogicException('Gold payload должен содержать список estimates.');
        }

        $estimates = [];

        foreach ($value['estimates'] as $estimate) {
            if (! is_array($estimate) || array_is_list($estimate)) {
                throw new \LogicException('Каждый gold estimate должен быть объектом с именованными полями.');
            }

            /** @var array<string, mixed> $estimate */
            $estimates[] = $estimate;
        }

        return ['estimates' => $estimates];
    }
}
