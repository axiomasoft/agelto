<?php

declare(strict_types=1);

namespace App\Services\Procurement\Gold;

use App\Dto\Procurement\Analyzers\Estimate\EstimatePassport;
use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Dto\Procurement\Analyzers\Estimate\EstimateWorkType;
use App\Dto\Procurement\Analyzers\Estimate\PassportResponse;
use App\Dto\Procurement\Prepare\Gsfx\GsfxChapter;
use App\Dto\Procurement\Prepare\Gsfx\GsfxDocument;
use App\Dto\Procurement\Prepare\Gsfx\GsfxWorkType;
use App\Dto\Procurement\Prepare\Gsfx\GsfxWorkTypeGroup;
use App\Enums\Procurement\Estimate\CalcMethod;
use App\Services\Procurement\Analyzers\Estimate\Core\DecimalNormalizer;

/**
 * `GsfxDocument` → gold без записи на диск.
 */
final readonly class GsfxGoldMapper
{
    private const int QUANTITY_SCALE = 6;

    public function __construct(
        private GsfxGoldRowExtractor $rowExtractor = new GsfxGoldRowExtractor,
    ) {}

    /** @return array<string, mixed> */
    public function toGold(GsfxDocument $document): array
    {
        $workTypeIndex = $this->buildWorkTypeIndex($document->work_type_groups);
        $rows = $this->rowExtractor->extractRows($document, $workTypeIndex);
        $passport = $this->mapPassport($document);

        return [
            'estimates' => [
                array_merge($passport->toArray(), [
                    'rows' => array_map(static fn (EstimateRow $row): array => $row->toArray(), $rows),
                ]),
            ],
        ];
    }

    /**
     * Частоты неотображённых элементов и атрибутов Data.xml для отчёта estimate:gold:build.
     * has_carrier=false означает, что маппер не назначил этим данным поле результата.
     *
     * @return array<string, array{count: int, has_carrier: bool}>
     */
    public function unmappedReport(GsfxDocument $document): array
    {
        $entries = $document->unmapped;

        if ($document->coefficients !== []) {
            $entries['Document.coefficients'] = count($document->coefficients);
        }

        return array_map(
            static fn (int $count): array => ['count' => $count, 'has_carrier' => false],
            $entries,
        );
    }

    private function mapPassport(GsfxDocument $document): PassportResponse
    {
        $passport = $document->passport;

        $rowsExpected = array_sum(array_map(
            static fn (GsfxChapter $chapter): int => count($chapter->positions),
            $document->chapters,
        ));

        return new PassportResponse(
            passport: new EstimatePassport(
                type: null,
                reference: null,
                target: null,
                address: $passport->object_name,
                generator: $passport->program_version !== null
                    ? "ГрандСмета {$passport->program_version}"
                    : 'ГрандСмета',
                contract_number: null,
                contract_date: null,
            ),
            estimate_number: $passport->loc_num,
            currency: 'RUB',
            base_year: $this->resolveBaseYear($passport->normative_base),
            price_level: $passport->normative_base,
            rows_expected: $rowsExpected,
            totals: $this->rowExtractor->mapTotals($document),
            calc_method: match ($passport->calc_method) {
                null => null,
                'Res' => CalcMethod::Resource,
                default => CalcMethod::BaseIndex,
            },
            labor_hours_workers: $this->sumResourceQuantity($document, 'Tzr'),
            labor_hours_machinists: $this->sumResourceQuantity($document, 'Tzm'),
            document_number: null,
            document_date: null,
            period_start: null,
            period_end: null,
            work_types: $this->mapWorkTypeGroups($document->work_type_groups),
        );
    }

    private function resolveBaseYear(?string $normativeBase): ?int
    {
        if ($normativeBase === null) {
            return null;
        }

        return preg_match('/(\d{4})/', $normativeBase, $matches) === 1 ? (int) $matches[1] : null;
    }

    private function sumResourceQuantity(GsfxDocument $document, string $resourceType): ?string
    {
        $sum = null;

        foreach ($document->chapters as $chapter) {
            foreach ($chapter->positions as $position) {
                foreach ($position->resources as $resource) {
                    if ($resource->resource_type !== $resourceType) {
                        continue;
                    }

                    $quantity = $this->normalizeDecimal($resource->quantity);

                    if ($quantity === null) {
                        continue;
                    }

                    $sum = bcadd($sum ?? '0', $quantity, self::QUANTITY_SCALE);
                }
            }
        }

        return $sum;
    }

    /**
     * @param  list<GsfxWorkTypeGroup>  $groups
     * @return list<EstimateWorkType>
     */
    private function mapWorkTypeGroups(array $groups): array
    {
        return array_map(
            static fn (GsfxWorkTypeGroup $group): EstimateWorkType => new EstimateWorkType(
                title: $group->caption,
                children: array_map(self::mapWorkType(...), $group->work_types),
            ),
            $groups,
        );
    }

    private static function mapWorkType(GsfxWorkType $workType): EstimateWorkType
    {
        return new EstimateWorkType(
            title: $workType->caption,
            overhead_code: $workType->nr_code,
            overhead_rate: self::normalizeDecimalStatic($workType->nacl),
            overhead_rate_curr: self::normalizeDecimalStatic($workType->nacl_curr),
            profit_code: $workType->sp_code,
            profit_rate: self::normalizeDecimalStatic($workType->plan),
            profit_rate_curr: self::normalizeDecimalStatic($workType->plan_curr),
        );
    }

    /**
     * @param  list<GsfxWorkTypeGroup>  $groups
     * @return array<string, GsfxWorkType>
     */
    private function buildWorkTypeIndex(array $groups): array
    {
        $index = [];

        foreach ($groups as $group) {
            foreach ($group->work_types as $workType) {
                if ($workType->id !== null) {
                    $index[$workType->id] = $workType;
                }
            }
        }

        return $index;
    }

    /** @return numeric-string|null */
    private function normalizeDecimal(?string $raw): ?string
    {
        return self::normalizeDecimalStatic($raw);
    }

    /** @return numeric-string|null */
    private static function normalizeDecimalStatic(?string $raw): ?string
    {
        return DecimalNormalizer::normalize($raw, allowLeadingDot: true, maxExponent: 50);
    }
}
