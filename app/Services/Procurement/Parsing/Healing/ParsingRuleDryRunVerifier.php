<?php

declare(strict_types=1);

namespace App\Services\Procurement\Parsing\Healing;

use App\Dto\Procurement\Parsing\Rules\DetailRuleSetDto;
use App\Dto\Procurement\Parsing\Rules\ListingRuleSetDto;
use App\Dto\Procurement\Parsing\Rules\StageParsingRules;
use App\Dto\Procurement\Parsing\Validation\DryRunSampleReport;
use App\Dto\Procurement\Parsing\Validation\DryRunVerificationReport;
use App\Enums\Procurement\Parsing\ParsingFormat;
use App\Services\Procurement\Platform\Contracts\DocumentListExtractor;
use App\Services\Procurement\Platform\Contracts\DomExtractor;
use App\Services\Procurement\Platform\Contracts\EventTableExtractor;
use App\Services\Procurement\Platform\Contracts\JsonPayloadExtractor;
use App\Services\Procurement\Platform\Contracts\TabularReader;
use App\Services\Procurement\Platform\Contracts\TabularSummaryMapper;
use App\Support\Time\Monotonic;
use Throwable;

/**
 * Out-of-band верификатор правил парсинга на наборе текстовых образцов без БД и HTTP.
 */
final class ParsingRuleDryRunVerifier
{
    public const int MAX_SAMPLE_BYTES = 5_242_880;

    public const int WALL_CLOCK_BUDGET_NS = 2_000_000_000;

    public function __construct(
        private readonly TabularReader $tabularReader,
        private readonly TabularSummaryMapper $tabularMapper,
        private readonly DomExtractor $domExtractor,
        private readonly DocumentListExtractor $documentListExtractor,
        private readonly EventTableExtractor $eventTableExtractor,
        private readonly JsonPayloadExtractor $jsonExtractor,
    ) {}

    /**
     * @param  array<string, string>|list<array{name: string, part: string, body: string, negative?: bool}>  $samples
     */
    public function verify(
        StageParsingRules $candidate,
        array $samples,
        ?StageParsingRules $baseline = null,
        int $maxSampleBytes = self::MAX_SAMPLE_BYTES,
        int $wallClockBudgetNs = self::WALL_CLOCK_BUDGET_NS,
    ): DryRunVerificationReport {
        $normalizedSamples = $this->normalizeSamples($samples);

        $candidateReports = $this->runSamples($candidate, $normalizedSamples, $maxSampleBytes, $wallClockBudgetNs);
        $passedCount = 0;
        foreach ($candidateReports as $report) {
            if ($report->passed) {
                $passedCount++;
            }
        }

        $allPassed = $normalizedSamples !== [] && $passedCount === count($candidateReports);

        if ($baseline === null) {
            return new DryRunVerificationReport(
                passed: $allPassed,
                samplesCount: count($candidateReports),
                passedCount: $passedCount,
                samples: $candidateReports,
                improved: false,
                regressions: [],
            );
        }

        $baselineReports = $this->runSamples($baseline, $normalizedSamples, $maxSampleBytes, $wallClockBudgetNs);
        $regressions = [];
        $improved = false;

        foreach ($normalizedSamples as $index => $sample) {
            $cand = $candidateReports[$index] ?? null;
            $base = $baselineReports[$index] ?? null;

            if ($cand === null || $base === null) {
                continue;
            }

            if ($sample['negative']) {
                if ($base->passed && ! $cand->passed) {
                    $regressions[] = sprintf(
                        'Ложное срабатывание на негативном образце "%s": кандидат извлёк %d записей.',
                        $sample['name'],
                        $cand->extractedCount,
                    );
                }

                continue;
            }

            if ($base->passed && ! $cand->passed) {
                $regressions[] = sprintf(
                    'Регрессия на образце "%s": базовая версия проходила, кандидат завершился с ошибкой.',
                    $sample['name'],
                );
            } elseif ($cand->extractedCount < $base->extractedCount) {
                $regressions[] = sprintf(
                    'Регрессия полноты на образце "%s": извлечено %d вместо %d.',
                    $sample['name'],
                    $cand->extractedCount,
                    $base->extractedCount,
                );
            }

            $newMissing = array_diff($cand->missingRequiredFields, $base->missingRequiredFields);
            if ($newMissing !== []) {
                $regressions[] = sprintf(
                    'Регрессия обязательных полей на образце "%s": отсутствуют %s.',
                    $sample['name'],
                    implode(', ', $newMissing),
                );
            }

            if ((! $base->passed && $cand->passed) || $cand->extractedCount > $base->extractedCount) {
                $improved = true;
            }
        }

        if ($regressions !== []) {
            $improved = false;
        }

        return new DryRunVerificationReport(
            passed: $allPassed,
            samplesCount: count($candidateReports),
            passedCount: $passedCount,
            samples: $candidateReports,
            improved: $improved,
            regressions: $regressions,
        );
    }

    /**
     * @param  array<string, string>|list<array{name: string, part: string, body: string, negative?: bool}>  $samples
     * @return list<array{name: string, part: string, body: string, negative: bool}>
     */
    private function normalizeSamples(array $samples): array
    {
        if ($samples === []) {
            return [];
        }

        $normalized = [];
        foreach ($samples as $key => $value) {
            if (is_string($value)) {
                $normalized[] = [
                    'name' => is_string($key) && $key !== '' ? $key : 'sample_'.(string) $key,
                    'part' => 'default',
                    'body' => $value,
                    'negative' => false,
                ];

                continue;
            }

            $name = $value['name'] !== '' ? $value['name'] : 'sample_'.(string) $key;
            $part = $value['part'] !== '' ? $value['part'] : 'default';
            $body = $value['body'];
            $negative = (bool) ($value['negative'] ?? false);

            $normalized[] = [
                'name' => $name,
                'part' => $part,
                'body' => $body,
                'negative' => $negative,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array{name: string, part: string, body: string, negative: bool}>  $samples
     * @return list<DryRunSampleReport>
     */
    private function runSamples(
        StageParsingRules $ruleSet,
        array $samples,
        int $maxSampleBytes,
        int $wallClockBudgetNs,
    ): array {
        $startedAt = Monotonic::nanoseconds();
        $reports = [];

        foreach ($samples as $sample) {
            if ((Monotonic::nanoseconds() - $startedAt) > $wallClockBudgetNs) {
                $reports[] = new DryRunSampleReport(
                    sampleKey: $sample['name'],
                    passed: false,
                    extractedCount: 0,
                    missingRequiredFields: [],
                    errors: ['Превышен лимит времени пробного прогона (wall-clock budget 2s).'],
                );

                continue;
            }

            if (strlen($sample['body']) > $maxSampleBytes) {
                $reports[] = new DryRunSampleReport(
                    sampleKey: $sample['name'],
                    passed: false,
                    extractedCount: 0,
                    missingRequiredFields: [],
                    errors: [sprintf('Размер образца "%s" превышает лимит 5 МБ.', $sample['name'])],
                );

                continue;
            }

            try {
                $reports[] = $this->runSingleSample($ruleSet, $sample);
            } catch (Throwable $e) {
                $reports[] = new DryRunSampleReport(
                    sampleKey: $sample['name'],
                    passed: false,
                    extractedCount: 0,
                    missingRequiredFields: [],
                    errors: [$e->getMessage()],
                );
            }
        }

        return $reports;
    }

    /**
     * @param  array{name: string, part: string, body: string, negative: bool}  $sample
     */
    private function runSingleSample(StageParsingRules $ruleSet, array $sample): DryRunSampleReport
    {
        if ($ruleSet instanceof ListingRuleSetDto) {
            return $this->runListingSample($ruleSet, $sample);
        }

        if ($ruleSet instanceof DetailRuleSetDto) {
            return $this->runDetailSample($ruleSet, $sample);
        }

        return new DryRunSampleReport(
            sampleKey: $sample['name'],
            passed: false,
            errors: [sprintf('Неподдерживаемый тип правил: %s.', $ruleSet::class)],
        );
    }

    /**
     * @param  array{name: string, part: string, body: string, negative: bool}  $sample
     */
    private function runListingSample(ListingRuleSetDto $ruleSet, array $sample): DryRunSampleReport
    {
        $negative = $sample['negative'];

        if ($ruleSet->format === ParsingFormat::Csv) {
            if ($ruleSet->csvDialect === null || $ruleSet->columns === null) {
                return new DryRunSampleReport($sample['name'], false, errors: ['CSV listing rules missing dialect or columns.']);
            }

            $table = $this->tabularReader->read($sample['body'], $ruleSet->csvDialect);
            $mapped = $this->tabularMapper->map($table['headers'], $table['rows'], $ruleSet->columns);
            $extractedCount = count($mapped['items']);
            $missing = $mapped['summary']->missingRequiredColumns;

            if ($negative) {
                return new DryRunSampleReport(
                    sampleKey: $sample['name'],
                    passed: $extractedCount === 0,
                    extractedCount: $extractedCount,
                    errors: $extractedCount > 0 ? ['Ложное срабатывание на негативном образце.'] : [],
                );
            }

            $passed = $extractedCount > 0 && $missing === [];
            $errors = $extractedCount === 0 ? ['Не извлечено ни одной записи листинга.'] : [];

            return new DryRunSampleReport(
                sampleKey: $sample['name'],
                passed: $passed,
                extractedCount: $extractedCount,
                missingRequiredFields: $missing,
                errors: $errors,
            );
        }

        if ($ruleSet->format === ParsingFormat::Json) {
            if ($ruleSet->jsonListing === null) {
                return new DryRunSampleReport($sample['name'], false, errors: ['JSON listing rules missing json_listing.']);
            }

            $decoded = json_decode($sample['body'], true);
            if (! is_array($decoded)) {
                return new DryRunSampleReport(
                    sampleKey: $sample['name'],
                    passed: $negative,
                    errors: $negative ? [] : ['Некорректный JSON-документ листинга.'],
                );
            }

            /** @var array<string, mixed> $decoded */
            $extracted = $this->jsonExtractor->extractListing($decoded, $ruleSet->jsonListing);
            $extractedCount = count($extracted['items']);

            if ($negative) {
                return new DryRunSampleReport(
                    sampleKey: $sample['name'],
                    passed: $extractedCount === 0,
                    extractedCount: $extractedCount,
                    errors: $extractedCount > 0 ? ['Ложное срабатывание на негативном образце.'] : [],
                );
            }

            return new DryRunSampleReport(
                sampleKey: $sample['name'],
                passed: $extractedCount > 0,
                extractedCount: $extractedCount,
                errors: $extractedCount === 0 ? ['JSON-листинг вернул пустой список.'] : [],
            );
        }

        // HTML listing
        if ($ruleSet->columns === null || $ruleSet->columns->rowSelectors === null) {
            return new DryRunSampleReport($sample['name'], false, errors: ['HTML listing rules require columns.row_selectors.']);
        }

        $xpath = $this->domExtractor->loadHtml($sample['body']);
        $rows = $this->domExtractor->queryAll($xpath, $ruleSet->columns->rowSelectors);
        $extractedCount = count($rows);

        if ($negative) {
            return new DryRunSampleReport(
                sampleKey: $sample['name'],
                passed: $extractedCount === 0,
                extractedCount: $extractedCount,
                errors: $extractedCount > 0 ? ['Ложное срабатывание на негативном образце.'] : [],
            );
        }

        return new DryRunSampleReport(
            sampleKey: $sample['name'],
            passed: $extractedCount > 0,
            extractedCount: $extractedCount,
            errors: $extractedCount === 0 ? ['HTML-листинг не нашёл ни одной строки.'] : [],
        );
    }

    /**
     * @param  array{name: string, part: string, body: string, negative: bool}  $sample
     */
    private function runDetailSample(DetailRuleSetDto $ruleSet, array $sample): DryRunSampleReport
    {
        $negative = $sample['negative'];
        $part = strtolower(trim($sample['part']));

        if ($ruleSet->format === ParsingFormat::Json) {
            if ($ruleSet->jsonDetail === null) {
                return new DryRunSampleReport($sample['name'], false, errors: ['JSON detail rules missing json_detail.']);
            }

            $decoded = json_decode($sample['body'], true);
            if (! is_array($decoded)) {
                return new DryRunSampleReport(
                    sampleKey: $sample['name'],
                    passed: $negative,
                    errors: $negative ? [] : ['Некорректный JSON-документ карточки.'],
                );
            }

            /** @var array<string, mixed> $decoded */
            $extracted = $this->jsonExtractor->extractDetail($decoded, $ruleSet->jsonDetail);
            $nonEmptyFields = array_filter($extracted['fields'], static fn (mixed $v): bool => $v !== null && $v !== '');
            $extractedCount = match ($part) {
                'documents' => count($extracted['documents']),
                'event-journal', 'events' => count($extracted['events']),
                default => count($nonEmptyFields) + count($extracted['documents']) + count($extracted['events']),
            };

            if ($negative) {
                return new DryRunSampleReport(
                    sampleKey: $sample['name'],
                    passed: $extractedCount === 0,
                    extractedCount: $extractedCount,
                    errors: $extractedCount > 0 ? ['Ложное срабатывание на негативном образце.'] : [],
                );
            }

            return new DryRunSampleReport(
                sampleKey: $sample['name'],
                passed: $extractedCount > 0,
                extractedCount: $extractedCount,
                errors: $extractedCount === 0 ? ['JSON-карточка не дала извлечённых данных.'] : [],
            );
        }

        // HTML detail
        $xpath = $this->domExtractor->loadHtml($sample['body']);
        $extractedCount = 0;

        if ($part === 'documents') {
            if ($ruleSet->documents !== null) {
                $extractedCount = count($this->documentListExtractor->extract($xpath, $ruleSet->documents));
            }
        } elseif ($part === 'event-journal' || $part === 'events') {
            if ($ruleSet->eventJournal !== null) {
                $extractedCount = count($this->eventTableExtractor->extract($xpath, $ruleSet->eventJournal));
            }
        } elseif ($part === 'common-info' || $part === 'dom') {
            if ($ruleSet->dom !== null) {
                $extractedCount = count($this->domExtractor->extractKeyValuePairs($xpath, $ruleSet->dom));
            }
        } else {
            if ($ruleSet->dom !== null) {
                $extractedCount += count($this->domExtractor->extractKeyValuePairs($xpath, $ruleSet->dom));
            }
            if ($ruleSet->documents !== null) {
                $extractedCount += count($this->documentListExtractor->extract($xpath, $ruleSet->documents));
            }
            if ($ruleSet->eventJournal !== null) {
                $extractedCount += count($this->eventTableExtractor->extract($xpath, $ruleSet->eventJournal));
            }
        }

        if ($negative) {
            return new DryRunSampleReport(
                sampleKey: $sample['name'],
                passed: $extractedCount === 0,
                extractedCount: $extractedCount,
                errors: $extractedCount > 0 ? ['Ложное срабатывание на негативном образце.'] : [],
            );
        }

        return new DryRunSampleReport(
            sampleKey: $sample['name'],
            passed: $extractedCount > 0,
            extractedCount: $extractedCount,
            errors: $extractedCount === 0 ? [sprintf('Не удалось извлечь данные для части "%s".', $part)] : [],
        );
    }
}
