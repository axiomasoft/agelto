<?php

declare(strict_types=1);

namespace App\Services\Procurement\Parsing\Validation;

use App\Dto\Procurement\Network\Http\SafeUrl;
use App\Dto\Procurement\Parsing\Primitives\CssSelector;
use App\Dto\Procurement\Parsing\Primitives\RegexPattern;
use App\Dto\Procurement\Parsing\Primitives\SelectorChain;
use App\Dto\Procurement\Parsing\Rules\DetailRuleSetDto;
use App\Dto\Procurement\Parsing\Rules\ListingRuleSetDto;
use App\Dto\Procurement\Parsing\Rules\StageParsingRules;
use App\Dto\Procurement\Parsing\Validation\RuleValidationIssue;
use App\Dto\Procurement\Parsing\Validation\RuleValidationResult;
use App\Dto\Procurement\Platform\Definition\PlatformDefinition;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Parsing\Schema\ParsingRuleSchemaRegistry;
use App\Support\Time\Monotonic;
use InvalidArgumentException;
use JsonException;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Throwable;

/**
 * Структурный и семантический валидатор правил парсинга.
 */
final class ParsingRuleValidator
{
    public const int MAX_JSON_BYTES = 262_144;

    private const int MAX_SCHEMA_ERRORS = 32;

    private const int VALIDATION_BUDGET_NANOSECONDS = 250_000_000;

    private const int MAX_CSV_HEADER_ROW_INDEX = 100;

    private const int MAX_PAGE_SIZE = 1_000;

    private const int MAX_COLUMNS_COUNT = 200;

    private const string PROBE_REG_NUMBER = '0173200001234000001';

    private CssSelectorConverter $cssConverter;

    public function __construct(
        private readonly ParsingRuleSchemaRegistry $schemaRegistry,
    ) {
        $this->cssConverter = new CssSelectorConverter(true);
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    public function validate(
        StageCode $stage,
        array $rules,
        int $schemaVersion = 1,
        ?PlatformDefinition $definition = null,
    ): RuleValidationResult {
        $startedAt = Monotonic::nanoseconds();
        $schemaDescriptor = $this->schemaRegistry->resolve($stage, $schemaVersion);
        $schemaId = $schemaDescriptor['schemaId'];
        $issues = [];

        try {
            $encoded = json_encode($rules, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            return new RuleValidationResult(
                valid: false,
                schemaId: $schemaId,
                schemaVersion: $schemaVersion,
                issues: [
                    new RuleValidationIssue('error', 'json.encode_failed', '$', $exception->getMessage()),
                ],
            );
        }

        if (strlen($encoded) > self::MAX_JSON_BYTES) {
            return new RuleValidationResult(
                valid: false,
                schemaId: $schemaId,
                schemaVersion: $schemaVersion,
                issues: [
                    new RuleValidationIssue(
                        'error',
                        'json.size_exceeded',
                        '$',
                        sprintf('Rules JSON exceeds maximum size of %d bytes.', self::MAX_JSON_BYTES),
                    ),
                ],
            );
        }

        $decodedObject = json_decode($encoded, false, 512, JSON_THROW_ON_ERROR);
        $schemaObject = $this->schemaRegistry->loadSchemaObject($stage, $schemaVersion);

        $this->validateAgainstJsonSchema($decodedObject, $schemaObject, $issues);
        $this->assertWithinBudget($startedAt, $issues);

        if ($issues === []) {
            $this->validateSemantics($stage, $rules, $definition, $startedAt, $issues);
            $this->assertWithinBudget($startedAt, $issues);
        }

        if ($issues === []) {
            $this->validateHydrationAndRoundTrip($stage, $rules, $schemaObject, $issues);
        }

        $hasErrors = array_any($issues, static fn (RuleValidationIssue $issue): bool => $issue->severity === 'error');

        if (! $hasErrors) {
            return new RuleValidationResult(
                valid: true,
                schemaId: $schemaId,
                schemaVersion: $schemaVersion,
                issues: $issues,
            );
        }

        return new RuleValidationResult(
            valid: false,
            schemaId: $schemaId,
            schemaVersion: $schemaVersion,
            issues: $issues,
        );
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    public function computeCanonicalHash(StageCode $stage, array $rules): ContentHash
    {
        $dto = $this->hydrateDto($stage, $rules);
        $canonicalJson = json_encode($dto->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return ContentHash::of($canonicalJson);
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    public function hydrateDto(StageCode $stage, array $rules): StageParsingRules
    {
        return match ($stage) {
            StageCode::Listing => ListingRuleSetDto::fromArray($rules),
            StageCode::Detail, StageCode::StatusRefresh => DetailRuleSetDto::fromArray($rules),
            default => throw new InvalidArgumentException(sprintf(
                'Stage "%s" does not support parsing rule DTO hydration.',
                $stage->value,
            )),
        };
    }

    /**
     * @param  list<RuleValidationIssue>  $issues
     */
    private function validateAgainstJsonSchema(mixed $data, object $schemaObject, array &$issues): void
    {
        $validator = new CompliantValidator(null, self::MAX_SCHEMA_ERRORS, false);
        $validator->setMaxErrors(self::MAX_SCHEMA_ERRORS);
        $result = $validator->validate($data, $schemaObject);

        if ($result->isValid()) {
            return;
        }

        $error = $result->error();
        if ($error === null) {
            $issues[] = new RuleValidationIssue('error', 'schema.invalid', '$', 'Schema validation failed.');

            return;
        }

        $formatted = (new ErrorFormatter)->format($error, true);
        foreach ($formatted as $path => $messages) {
            if (! is_array($messages)) {
                continue;
            }
            foreach ($messages as $message) {
                if (is_string($message)) {
                    $issues[] = new RuleValidationIssue(
                        severity: 'error',
                        code: 'schema.violation',
                        path: is_string($path) && $path !== '' ? $path : '$',
                        message: $message,
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  list<RuleValidationIssue>  $issues
     */
    private function validateSemantics(
        StageCode $stage,
        array $rules,
        ?PlatformDefinition $definition,
        int $startedAt,
        array &$issues,
    ): void {
        try {
            $dto = $this->hydrateDto($stage, $rules);
        } catch (Throwable $exception) {
            $issues[] = new RuleValidationIssue('error', 'dto.hydration_failed', '$', $exception->getMessage());

            return;
        }

        if ($dto instanceof ListingRuleSetDto) {
            $this->validateListingSemantics($dto, $definition, $issues);
        } elseif ($dto instanceof DetailRuleSetDto) {
            $this->validateDetailSemantics($dto, $definition, $issues);
        }

        $this->assertWithinBudget($startedAt, $issues);
    }

    /**
     * @param  list<RuleValidationIssue>  $issues
     */
    private function validateListingSemantics(
        ListingRuleSetDto $dto,
        ?PlatformDefinition $definition,
        array &$issues,
    ): void {
        $this->validateSelectorChain($dto->transport->itemLinkSelectors, '$.transport.item_link_selectors', $issues);
        $this->validateSelectorChain($dto->transport->nextPageSelectors, '$.transport.next_page_selectors', $issues);

        if ($dto->transport->defaultPageSize > self::MAX_PAGE_SIZE) {
            $issues[] = new RuleValidationIssue(
                'error',
                'listing.page_size_limit_exceeded',
                '$.transport.default_page_size',
                sprintf('default_page_size must not exceed %d.', self::MAX_PAGE_SIZE),
            );
        }

        if ($dto->csvDialect !== null && $dto->csvDialect->headerRowIndex > self::MAX_CSV_HEADER_ROW_INDEX) {
            $issues[] = new RuleValidationIssue(
                'error',
                'csv.header_row_index_exceeded',
                '$.csv_dialect.header_row_index',
                sprintf('header_row_index must not exceed %d.', self::MAX_CSV_HEADER_ROW_INDEX),
            );
        }

        if ($dto->columns !== null) {
            $this->validateSelectorChain($dto->columns->rowSelectors, '$.columns.row_selectors', $issues);

            if (count($dto->columns->columns) > self::MAX_COLUMNS_COUNT) {
                $issues[] = new RuleValidationIssue(
                    'error',
                    'listing.columns_limit_exceeded',
                    '$.columns.columns',
                    sprintf('columns count must not exceed %d.', self::MAX_COLUMNS_COUNT),
                );
            }

            foreach ($dto->columns->columns as $idx => $column) {
                $this->validateRegex($column->valueRegex, "$.columns.columns[{$idx}].value_regex", null, $issues);
            }
        }

        $this->validateRegex($dto->dateParsing->extractRegex, '$.date_parsing.extract_regex', null, $issues);
        $this->validateRegex($dto->externalIdRegex, '$.external_id_regex', null, $issues);

        if ($dto->detailUrlTemplate !== null) {
            $this->validateUrlTemplate($dto->detailUrlTemplate, '$.detail_url_template', $definition, $issues);
        }
    }

    /**
     * @param  list<RuleValidationIssue>  $issues
     */
    private function validateDetailSemantics(
        DetailRuleSetDto $dto,
        ?PlatformDefinition $definition,
        array &$issues,
    ): void {
        if ($dto->envelope !== null) {
            $this->validateSelectorChain($dto->envelope->titleSelectors, '$.envelope.title_selectors', $issues);
            $this->validateSelectorChain($dto->envelope->numberSelectors, '$.envelope.number_selectors', $issues);
            $this->validateSelectorChain($dto->envelope->statusSelectors, '$.envelope.status_selectors', $issues);
            $this->validateSelectorChain($dto->envelope->priceSelectors, '$.envelope.price_selectors', $issues);
            $this->validateSelectorChain($dto->envelope->currencySelectors, '$.envelope.currency_selectors', $issues);
            $this->validateSelectorChain($dto->envelope->lawSelectors, '$.envelope.law_selectors', $issues);
        }

        if ($dto->dom !== null) {
            foreach ($dto->dom->sectionSelectors as $section => $chain) {
                $this->validateSelectorChain($chain, "$.dom.section_selectors.{$section}", $issues);
            }
            $this->validateSelectorChain($dto->dom->fieldRowsSelectors, '$.dom.field_rows_selectors', $issues);
            $this->validateSelectorChain($dto->dom->labelSelectors, '$.dom.label_selectors', $issues);
            $this->validateSelectorChain($dto->dom->valueSelectors, '$.dom.value_selectors', $issues);
        }

        if ($dto->commonInfo !== null && $dto->commonInfo->dateParsing !== null) {
            $this->validateRegex($dto->commonInfo->dateParsing->extractRegex, '$.common_info.date_parsing.extract_regex', null, $issues);
        }

        if ($dto->parties !== null) {
            $this->validateSelectorChain($dto->parties->customerSectionSelectors, '$.parties.customer_section_selectors', $issues);
            $this->validateSelectorChain($dto->parties->customerNameSelectors, '$.parties.customer_name_selectors', $issues);
            $this->validateSelectorChain($dto->parties->customerInnSelectors, '$.parties.customer_inn_selectors', $issues);
            $this->validateSelectorChain($dto->parties->customerKppSelectors, '$.parties.customer_kpp_selectors', $issues);
            $this->validateSelectorChain($dto->parties->customerOgrnSelectors, '$.parties.customer_ogrn_selectors', $issues);
            $this->validateRegex($dto->parties->innRegex, '$.parties.inn_regex', null, $issues);
            $this->validateRegex($dto->parties->kppRegex, '$.parties.kpp_regex', null, $issues);
            $this->validateRegex($dto->parties->ogrnRegex, '$.parties.ogrn_regex', null, $issues);
        }

        if ($dto->documents !== null) {
            $this->validateSelectorChain($dto->documents->containerSelectors, '$.documents.container_selectors', $issues);
            $this->validateSelectorChain($dto->documents->itemSelectors, '$.documents.item_selectors', $issues);
            $this->validateSelectorChain($dto->documents->titleSelectors, '$.documents.title_selectors', $issues);
            $this->validateSelectorChain($dto->documents->urlSelectors, '$.documents.url_selectors', $issues);
            $this->validateSelectorChain($dto->documents->hashSelectors, '$.documents.hash_selectors', $issues);
            $this->validateSelectorChain($dto->documents->sizeSelectors, '$.documents.size_selectors', $issues);
            $this->validateSelectorChain($dto->documents->publishedAtSelectors, '$.documents.published_at_selectors', $issues);
        }

        if ($dto->eventJournal !== null) {
            $this->validateSelectorChain($dto->eventJournal->rowSelectors, '$.event_journal.row_selectors', $issues);
            $this->validateSelectorChain($dto->eventJournal->dateSelectors, '$.event_journal.date_selectors', $issues);
            $this->validateSelectorChain($dto->eventJournal->descriptionSelectors, '$.event_journal.description_selectors', $issues);

            if ($dto->eventJournal->dateParsing !== null) {
                $this->validateRegex($dto->eventJournal->dateParsing->extractRegex, '$.event_journal.date_parsing.extract_regex', null, $issues);
            }

            foreach ($dto->eventJournal->classifications as $idx => $classification) {
                $this->validateRegex($classification->pattern, "$.event_journal.classifications[{$idx}].pattern", null, $issues);
            }
        }

        if ($dto->supplierResults !== null) {
            $this->validateSelectorChain($dto->supplierResults->containerSelectors, '$.supplier_results.container_selectors', $issues);
            $this->validateSelectorChain($dto->supplierResults->participantRowSelectors, '$.supplier_results.participant_row_selectors', $issues);
            $this->validateSelectorChain($dto->supplierResults->nameSelectors, '$.supplier_results.name_selectors', $issues);
            $this->validateSelectorChain($dto->supplierResults->innSelectors, '$.supplier_results.inn_selectors', $issues);
            $this->validateSelectorChain($dto->supplierResults->priceSelectors, '$.supplier_results.price_selectors', $issues);
            $this->validateSelectorChain($dto->supplierResults->statusSelectors, '$.supplier_results.status_selectors', $issues);
        }

        if ($dto->routes !== null) {
            foreach ([
                'common_info_path' => $dto->routes->commonInfoPath,
                'documents_path' => $dto->routes->documentsPath,
                'journal_path' => $dto->routes->journalPath,
                'supplier_results_path' => $dto->routes->supplierResultsPath,
                'lot_path' => $dto->routes->lotPath,
            ] as $field => $template) {
                if ($template === null) {
                    continue;
                }
                if (str_starts_with($template, 'https://')) {
                    $this->validateUrlTemplate($template, "$.routes.{$field}", $definition, $issues);
                } elseif (! str_starts_with($template, '/') || str_starts_with($template, '//')) {
                    $issues[] = new RuleValidationIssue(
                        'error',
                        'routes.invalid_path_or_url',
                        "$.routes.{$field}",
                        'Route template must be a relative path starting with "/" (not "//") or an https:// URL.',
                    );
                } else {
                    if (substr_count($template, '{regNumber}') !== 1) {
                        $issues[] = new RuleValidationIssue(
                            'error',
                            'url.missing_or_duplicate_placeholder',
                            "$.routes.{$field}",
                            'Route template must contain {regNumber} placeholder exactly once.',
                        );
                    }
                    if (str_contains($template, '#')) {
                        $issues[] = new RuleValidationIssue(
                            'error',
                            'routes.fragment_forbidden',
                            "$.routes.{$field}",
                            'Route template must not contain fragment "#".',
                        );
                    }
                }
            }
        }
    }

    /**
     * @param  list<RuleValidationIssue>  $issues
     */
    private function validateSelectorChain(?SelectorChain $chain, string $path, array &$issues): void
    {
        if ($chain === null) {
            return;
        }

        foreach ($chain->selectors as $index => $selector) {
            $this->validateCssSelector($selector, "{$path}[{$index}]", $issues);
        }
    }

    /**
     * @param  list<RuleValidationIssue>  $issues
     */
    private function validateCssSelector(CssSelector $selector, string $path, array &$issues): void
    {
        if (str_contains(strtolower($selector->selector), ':contains(')) {
            $issues[] = new RuleValidationIssue(
                'error',
                'css.forbidden_pseudo_class',
                $path,
                sprintf('CSS selector "%s" uses forbidden :contains() pseudo-class.', $selector->selector),
            );

            return;
        }

        try {
            $this->cssConverter->toXPath($selector->selector);
        } catch (Throwable $exception) {
            $issues[] = new RuleValidationIssue(
                'error',
                'css.invalid_selector',
                $path,
                sprintf('Invalid CSS selector "%s": %s', $selector->selector, $exception->getMessage()),
            );
        }
    }

    /**
     * @param  list<RuleValidationIssue>  $issues
     */
    private function validateRegex(?RegexPattern $pattern, string $path, ?int $minCaptureGroups, array &$issues): void
    {
        if ($pattern === null) {
            return;
        }

        try {
            SafeRegex::assertSafe($pattern->pattern, $minCaptureGroups);
        } catch (InvalidArgumentException $exception) {
            $issues[] = new RuleValidationIssue('error', 'regex.unsafe_or_invalid', $path, $exception->getMessage());
        }
    }

    /**
     * @param  list<RuleValidationIssue>  $issues
     */
    private function validateUrlTemplate(
        string $template,
        string $path,
        ?PlatformDefinition $definition,
        array &$issues,
    ): void {
        if (substr_count($template, '{regNumber}') !== 1) {
            $issues[] = new RuleValidationIssue(
                'error',
                'url.missing_or_duplicate_placeholder',
                $path,
                'URL template must contain {regNumber} placeholder exactly once.',
            );

            return;
        }

        $schemeAuthority = explode('/', $template, 4);
        $authorityPrefix = implode('/', array_slice($schemeAuthority, 0, 3));
        if (str_contains($authorityPrefix, '{') || str_contains($authorityPrefix, '}')) {
            $issues[] = new RuleValidationIssue(
                'error',
                'url.placeholder_in_authority',
                $path,
                'URL template placeholder is forbidden in scheme or authority.',
            );

            return;
        }

        $probe = str_replace('{regNumber}', self::PROBE_REG_NUMBER, $template);
        $parts = parse_url($probe);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https') {
            $issues[] = new RuleValidationIssue(
                'error',
                'url.https_required',
                $path,
                'URL template must use https scheme.',
            );

            return;
        }

        $pathAndQuery = ($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
        if (! str_contains($pathAndQuery, self::PROBE_REG_NUMBER)) {
            $issues[] = new RuleValidationIssue(
                'error',
                'url.placeholder_location',
                $path,
                'URL template placeholder {regNumber} must be in path or query.',
            );

            return;
        }

        try {
            $safeUrl = new SafeUrl($probe);
        } catch (InvalidArgumentException $exception) {
            $issues[] = new RuleValidationIssue('error', 'url.invalid', $path, $exception->getMessage());

            return;
        }

        if ($definition !== null) {
            $origins = $definition->sourceTargets->origins();
            if ($origins !== [] && ! (new UrlPolicy($origins))->allows($safeUrl)) {
                $issues[] = new RuleValidationIssue(
                    'error',
                    'url.origin_not_allowed',
                    $path,
                    sprintf('URL template origin "%s" is not in PlatformDefinition sourceTargets.', $safeUrl->origin->value()),
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  list<RuleValidationIssue>  $issues
     */
    private function validateHydrationAndRoundTrip(
        StageCode $stage,
        array $rules,
        object $schemaObject,
        array &$issues,
    ): void {
        try {
            $dto = $this->hydrateDto($stage, $rules);
            $roundTripArray = $dto->toArray();
            $rehydrated = $this->hydrateDto($stage, $roundTripArray);

            if ($rehydrated != $dto) {
                $issues[] = new RuleValidationIssue(
                    'error',
                    'dto.round_trip_mismatch',
                    '$',
                    'DTO round-trip equality check failed.',
                );

                return;
            }

            $encoded = json_encode($roundTripArray, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $decoded = json_decode($encoded, false, 512, JSON_THROW_ON_ERROR);
            $this->validateAgainstJsonSchema($decoded, $schemaObject, $issues);
        } catch (Throwable $exception) {
            $issues[] = new RuleValidationIssue('error', 'dto.round_trip_failed', '$', $exception->getMessage());
        }
    }

    /**
     * @param  list<RuleValidationIssue>  $issues
     */
    private function assertWithinBudget(int $startedAt, array &$issues): void
    {
        if ((Monotonic::nanoseconds() - $startedAt) > self::VALIDATION_BUDGET_NANOSECONDS) {
            $issues[] = new RuleValidationIssue(
                'error',
                'validation.budget_exceeded',
                '$',
                'Parsing rule validation exceeded 250ms budget.',
            );
        }
    }
}
