<?php

declare(strict_types=1);

namespace App\Services\Procurement\Parsing\Healing;

use App\Dto\Procurement\Parsing\Extraction\DriftSignal;
use App\Dto\Procurement\Parsing\Rules\ListingRuleSetDto;
use App\Dto\Procurement\Parsing\Validation\DryRunVerificationReport;
use App\Dto\Procurement\Platform\Definition\PlatformDefinition;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Enums\Procurement\Parsing\AutoHealMode;
use App\Enums\Procurement\Parsing\ParsingFormat;
use App\Enums\Procurement\Parsing\ParsingRuleActorType;
use App\Enums\Procurement\Parsing\ParsingRuleEventType;
use App\Enums\Procurement\Parsing\ParsingRuleSource;
use App\Enums\Procurement\Parsing\ParsingRuleVersionStatus;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;
use App\Models\Procurement\Parsing\ParsingRuleSet;
use App\Models\Procurement\Parsing\ParsingRuleVersion;
use App\Repositories\Procurement\Parsing\ParsingRuleReadRepository;
use App\Repositories\Procurement\Parsing\ParsingRuleStoreRepository;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Parsing\Validation\ParsingRuleValidator;
use App\Services\Procurement\Platform\Contracts\DomExtractor;
use App\Services\Procurement\Platform\Contracts\TabularReader;
use App\Services\Procurement\Platform\PlatformRegistry;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;

/**
 * Out-of-band сервис самокоррекции правил парсинга (строго вне fetch/finalizer транзакций).
 */
final class ParsingRuleAutoHealer
{
    /**
     * Известные статические метки интерфейса площадки (никогда не пользовательский текст закупки).
     */
    private const array KNOWN_STATIC_LABELS = [
        'Идентификационный код закупки (ИКЗ)',
        'Наименование электронной площадки',
        'Способ определения поставщика',
        'Начальная (максимальная) цена контракта',
        'Дата и время окончания срока подачи заявок',
    ];

    public function __construct(
        private readonly ParsingRuleValidator $validator,
        private readonly ParsingRuleDryRunVerifier $verifier,
        private readonly ParsingRuleReadRepository $readRepository,
        private readonly ParsingRuleStoreRepository $storeRepository,
        private readonly DomExtractor $domExtractor,
        private readonly TabularReader $tabularReader,
    ) {}

    /**
     * @param  list<DriftSignal|array{field_or_selector?: string, expected?: string|null, actual?: string|null, fallback_index?: int}>  $driftSignals
     * @param  array<string, string>|list<array{name: string, part: string, body: string, negative?: bool}>  $samples
     * @param  array<string, string>|list<array{name: string, part: string, body: string, negative?: bool}>  $regressionFixtures
     */
    public function proposeFallbackPromotion(
        ParsingRuleSet $ruleSet,
        array $driftSignals,
        array $samples,
        array $regressionFixtures = [],
        bool $autoActivate = false,
        ParsingRuleActorType $actorType = ParsingRuleActorType::AutoHeal,
        ?int $actorId = null,
    ): ?ParsingRuleVersion {
        if ($ruleSet->auto_heal_mode === AutoHealMode::Disabled) {
            if ($autoActivate) {
                throw new \DomainException('Auto-heal is disabled for this parsing rule set.');
            }

            return null;
        }

        $activeVersion = $this->resolveActiveVersion($ruleSet);
        if ($activeVersion === null) {
            return null;
        }

        $candidateRules = $this->applyFallbackPromotions($activeVersion->rules_json, $driftSignals);

        if ($candidateRules === null) {
            return null;
        }

        $this->recordDriftEvents($ruleSet, $activeVersion->id, $driftSignals, $actorType, $actorId);

        return $this->verifyAndPersistCandidate(
            ruleSet: $ruleSet,
            baselineVersion: $activeVersion,
            candidateRules: $candidateRules,
            samples: $samples,
            regressionFixtures: $regressionFixtures,
            changeSummary: 'Auto-heal: продвижение сработавшего fallback-селектора на позицию 0.',
            autoActivate: $autoActivate,
            actorType: $actorType,
            actorId: $actorId,
        );
    }

    /**
     * @param  array<string, string>|list<array{name: string, part: string, body: string, negative?: bool}>  $regressionFixtures
     */
    public function discoverCandidates(
        ParsingRuleSet $ruleSet,
        string $brokenSample,
        ?string $part = null,
        array $regressionFixtures = [],
        bool $autoActivate = false,
        ParsingRuleActorType $actorType = ParsingRuleActorType::AutoHeal,
        ?int $actorId = null,
    ): ?ParsingRuleVersion {
        if ($ruleSet->auto_heal_mode === AutoHealMode::Disabled) {
            if ($autoActivate) {
                throw new \DomainException('Auto-heal is disabled for this parsing rule set.');
            }

            return null;
        }

        $activeVersion = $this->resolveActiveVersion($ruleSet);
        if ($activeVersion === null) {
            return null;
        }

        $candidateRules = $activeVersion->rules_json;
        $discovered = false;
        $effectivePart = $part !== null ? strtolower(trim($part)) : null;

        if ($ruleSet->stage === StageCode::Listing) {
            $discovered = $this->discoverCsvListingCandidate($candidateRules, $brokenSample);
            $samples = [
                [
                    'name' => 'broken_listing_sample',
                    'part' => 'listing',
                    'body' => $brokenSample,
                    'negative' => false,
                ],
            ];
        } elseif ($ruleSet->stage === StageCode::Detail) {
            $xpath = $this->domExtractor->loadHtml($brokenSample);

            if ($effectivePart === 'documents') {
                $discovered = $this->discoverDocumentsCandidate($candidateRules, $xpath);
            } elseif ($effectivePart === 'event-journal' || $effectivePart === 'events') {
                $discovered = $this->discoverEventJournalCandidate($candidateRules, $xpath);
            } elseif ($effectivePart === 'common-info' || $effectivePart === 'dom') {
                $discovered = $this->discoverCommonInfoCandidate($candidateRules, $xpath);
            } else {
                $docsChanged = $this->discoverDocumentsCandidate($candidateRules, $xpath);
                $eventsChanged = $this->discoverEventJournalCandidate($candidateRules, $xpath);
                $infoChanged = $this->discoverCommonInfoCandidate($candidateRules, $xpath);
                $discovered = $docsChanged || $eventsChanged || $infoChanged;
            }

            $samples = [
                [
                    'name' => 'broken_detail_sample',
                    'part' => $effectivePart ?? 'default',
                    'body' => $brokenSample,
                    'negative' => false,
                ],
            ];
        } else {
            return null;
        }

        if (! $discovered) {
            return null;
        }

        $this->storeRepository->recordEvent(
            ruleSetId: $ruleSet->id,
            ruleVersionId: $activeVersion->id,
            eventType: ParsingRuleEventType::DriftDetected,
            actorType: $actorType,
            actorId: $actorId,
            reason: sprintf('Обнаружен структурный дрейф (%s), запущен поиск кандидата.', $effectivePart ?? $ruleSet->stage->value),
            metadata: ['part' => $effectivePart, 'stage' => $ruleSet->stage->value],
        );

        return $this->verifyAndPersistCandidate(
            ruleSet: $ruleSet,
            baselineVersion: $activeVersion,
            candidateRules: $candidateRules,
            samples: $samples,
            regressionFixtures: $regressionFixtures,
            changeSummary: sprintf('Auto-heal: структурный кандидат по DOM/CSV-якорям (%s).', $effectivePart ?? $ruleSet->stage->value),
            autoActivate: $autoActivate,
            actorType: $actorType,
            actorId: $actorId,
            allowModeAutoActivate: false,
        );
    }

    private function resolveActiveVersion(ParsingRuleSet $ruleSet): ?ParsingRuleVersion
    {
        if ($ruleSet->active_version_id === null) {
            return null;
        }

        return $this->readRepository->findVersionPayloadById($ruleSet->active_version_id);
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  list<DriftSignal|array{field_or_selector?: string, expected?: string|null, actual?: string|null, fallback_index?: int}>  $driftSignals
     * @return array<string, mixed>|null
     */
    private function applyFallbackPromotions(array $rules, array $driftSignals): ?array
    {
        $changed = false;
        $current = $rules;

        foreach ($driftSignals as $signal) {
            $fieldOrSelector = $signal instanceof DriftSignal
                ? $signal->fieldOrSelector
                : ($signal['field_or_selector'] ?? '');
            $expected = $signal instanceof DriftSignal
                ? $signal->expected
                : ($signal['expected'] ?? null);
            $actual = $signal instanceof DriftSignal
                ? $signal->actual
                : ($signal['actual'] ?? null);
            $explicitIndex = $signal instanceof DriftSignal
                ? null
                : ($signal['fallback_index'] ?? null);

            $result = $this->promoteInTree($current, '', $fieldOrSelector, $expected, $actual, $explicitIndex);
            if ($result['changed']) {
                /** @var array<string, mixed> $next */
                $next = $result['node'];
                $current = $next;
                $changed = true;
            }
        }

        return $changed ? $current : null;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array{node: array<array-key, mixed>, changed: bool}
     */
    private function promoteInTree(
        array $node,
        string $currentPath,
        string $targetPath,
        ?string $expected,
        ?string $actual,
        ?int $explicitIndex,
    ): array {
        if ($this->isStringList($node)) {
            /** @var list<string> $node */
            $fallbackIndex = $this->resolveFallbackIndex($node, $currentPath, $targetPath, $expected, $actual, $explicitIndex);
            if ($fallbackIndex !== null && $fallbackIndex > 0 && isset($node[$fallbackIndex])) {
                $promoted = $node[$fallbackIndex];
                $reordered = [$promoted];
                foreach ($node as $idx => $item) {
                    if ($idx !== $fallbackIndex) {
                        $reordered[] = $item;
                    }
                }

                return ['node' => $reordered, 'changed' => true];
            }

            return ['node' => $node, 'changed' => false];
        }

        $changed = false;
        foreach ($node as $key => $child) {
            if (! is_array($child)) {
                continue;
            }
            $childPath = $currentPath === '' ? (string) $key : $currentPath.'.'.(string) $key;
            $result = $this->promoteInTree($child, $childPath, $targetPath, $expected, $actual, $explicitIndex);
            if ($result['changed']) {
                $node[$key] = $result['node'];
                $changed = true;
            }
        }

        return ['node' => $node, 'changed' => $changed];
    }

    /**
     * @param  list<string>  $chain
     */
    private function resolveFallbackIndex(
        array $chain,
        string $currentPath,
        string $targetPath,
        ?string $expected,
        ?string $actual,
        ?int $explicitIndex,
    ): ?int {
        if (count($chain) < 2) {
            return null;
        }

        $pathMatches = $targetPath !== '' && ($currentPath === $targetPath || str_ends_with($currentPath, '.'.$targetPath));
        $expectedMatches = $expected !== null && $chain[0] === $expected;

        if (! $pathMatches && ! $expectedMatches) {
            return null;
        }

        if ($explicitIndex !== null && $explicitIndex > 0 && isset($chain[$explicitIndex])) {
            return $explicitIndex;
        }

        if ($actual !== null) {
            if (ctype_digit($actual)) {
                $idx = (int) $actual;
                if ($idx > 0 && isset($chain[$idx])) {
                    return $idx;
                }
            }

            $idx = array_search($actual, $chain, true);
            if (is_int($idx) && $idx > 0) {
                return $idx;
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function isStringList(array $value): bool
    {
        if ($value === [] || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function discoverDocumentsCandidate(array &$rules, DOMXPath $xpath): bool
    {
        $links = $xpath->query('//a[contains(@href, "filestore")]');
        if (! $links instanceof DOMNodeList || $links->length === 0) {
            return false;
        }

        $firstLink = $links->item(0);
        if (! $firstLink instanceof DOMElement) {
            return false;
        }

        $itemNode = $this->findRepeatingItemAncestor($firstLink);
        if ($itemNode === null) {
            return false;
        }

        $containerNode = $itemNode->parentNode instanceof DOMElement ? $itemNode->parentNode : null;
        if ($containerNode !== null && strtolower($containerNode->tagName) === 'tbody' && $containerNode->parentNode instanceof DOMElement) {
            $containerNode = $containerNode->parentNode;
        }

        $containerSelector = $containerNode !== null ? $this->buildElementSelector($containerNode) : null;
        $itemSelector = $this->buildElementSelector($itemNode);
        $titleSelector = $firstLink->parentNode instanceof DOMElement && strtolower($firstLink->parentNode->tagName) === 'span'
            ? 'span > a[href*="filestore"]'
            : 'a[href*="filestore"]';
        $urlSelector = 'a[href*="filestore"]';

        if (! isset($rules['documents']) || ! is_array($rules['documents'])) {
            $rules['documents'] = [];
        }

        /** @var array<string, mixed> $docs */
        $docs = $rules['documents'];
        $changed = false;

        if ($containerSelector !== null && $this->prependSelector($docs, 'container_selectors', $containerSelector)) {
            $changed = true;
        }
        if ($this->prependSelector($docs, 'item_selectors', $itemSelector)) {
            $changed = true;
        }
        if ($this->prependSelector($docs, 'title_selectors', $titleSelector)) {
            $changed = true;
        }
        if ($this->prependSelector($docs, 'url_selectors', $urlSelector)) {
            $changed = true;
        }

        $rules['documents'] = $docs;

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function discoverEventJournalCandidate(array &$rules, DOMXPath $xpath): bool
    {
        $tzNodes = $xpath->query('//span[contains(concat(" ", normalize-space(@class), " "), " timeZoneName ")]');
        if (! $tzNodes instanceof DOMNodeList || $tzNodes->length === 0) {
            return false;
        }

        $tzSpan = $tzNodes->item(0);
        if (! $tzSpan instanceof DOMElement) {
            return false;
        }

        $tableNode = $this->findAncestorByTag($tzSpan, 'table');
        if ($tableNode === null) {
            return false;
        }

        $tableSelector = $this->buildElementSelector($tableNode);
        $rowSelector = $tableSelector.' tbody tr';

        $tdNode = $this->findAncestorByTag($tzSpan, 'td');
        $trNode = $this->findAncestorByTag($tzSpan, 'tr');

        $dateSelector = 'td:has(span.timeZoneName)';
        $descriptionSelector = 'td:nth-child(2)';

        if ($trNode !== null && $tdNode !== null) {
            $tdIndex = 0;
            $tdCount = 0;
            foreach ($trNode->childNodes as $child) {
                if ($child instanceof DOMElement && strtolower($child->tagName) === 'td') {
                    $tdCount++;
                    if ($child->isSameNode($tdNode)) {
                        $tdIndex = $tdCount;
                    }
                }
            }

            if ($tdIndex > 0) {
                $dateSelector = sprintf('td:nth-child(%d)', $tdIndex);
                $descIndex = $tdIndex === 1 && $tdCount >= 2 ? 2 : ($tdIndex > 1 ? 1 : 2);
                $descriptionSelector = sprintf('td:nth-child(%d)', $descIndex);
            }
        }

        if (! isset($rules['event_journal']) || ! is_array($rules['event_journal'])) {
            $rules['event_journal'] = [];
        }

        /** @var array<string, mixed> $journal */
        $journal = $rules['event_journal'];
        $changed = false;
        if ($this->prependSelector($journal, 'row_selectors', $rowSelector)) {
            $changed = true;
        }
        if ($this->prependSelector($journal, 'date_selectors', $dateSelector)) {
            $changed = true;
        }
        if ($this->prependSelector($journal, 'description_selectors', $descriptionSelector)) {
            $changed = true;
        }

        $rules['event_journal'] = $journal;

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function discoverCommonInfoCandidate(array &$rules, DOMXPath $xpath): bool
    {
        $matchedLabelNode = null;

        foreach (self::KNOWN_STATIC_LABELS as $staticLabel) {
            $nodes = $xpath->query(sprintf('//*[not(*) and contains(normalize-space(.), "%s")]', $staticLabel));
            if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
                $candidate = $nodes->item(0);
                if ($candidate instanceof DOMElement) {
                    $matchedLabelNode = $candidate;
                    break;
                }
            }
        }

        if ($matchedLabelNode === null) {
            return false;
        }

        $rowNode = $matchedLabelNode->parentNode instanceof DOMElement ? $matchedLabelNode->parentNode : null;
        if ($rowNode === null) {
            return false;
        }

        $valueNode = $this->findSiblingElement($matchedLabelNode);
        if ($valueNode === null && $rowNode->parentNode instanceof DOMElement) {
            $parentRow = $rowNode->parentNode;
            $sibling = $this->findSiblingElement($rowNode);
            if ($sibling !== null) {
                $rowNode = $parentRow;
                $valueNode = $sibling;
            }
        }

        if ($valueNode === null) {
            return false;
        }

        $sectionNode = $rowNode->parentNode instanceof DOMElement ? $rowNode->parentNode : null;

        if (! isset($rules['dom']) || ! is_array($rules['dom'])) {
            $rules['dom'] = [];
        }

        /** @var array<string, mixed> $dom */
        $dom = $rules['dom'];
        $changed = false;

        if ($this->prependSelector($dom, 'field_rows_selectors', $this->buildElementSelector($rowNode))) {
            $changed = true;
        }
        if ($this->prependSelector($dom, 'label_selectors', $this->buildElementSelector($matchedLabelNode))) {
            $changed = true;
        }
        if ($this->prependSelector($dom, 'value_selectors', $this->buildElementSelector($valueNode))) {
            $changed = true;
        }

        if ($sectionNode !== null) {
            if (! isset($dom['section_selectors']) || ! is_array($dom['section_selectors'])) {
                $dom['section_selectors'] = [];
            }
            /** @var array<string, mixed> $sections */
            $sections = $dom['section_selectors'];
            if ($this->prependSelector($sections, 'common_info', $this->buildElementSelector($sectionNode))) {
                $changed = true;
            }
            $dom['section_selectors'] = $sections;
        }

        $rules['dom'] = $dom;

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function discoverCsvListingCandidate(array &$rules, string $brokenSample): bool
    {
        if (($rules['format'] ?? null) !== ParsingFormat::Csv->value) {
            return false;
        }

        if (! isset($rules['csv_dialect']) || ! is_array($rules['csv_dialect']) || ! isset($rules['columns']) || ! is_array($rules['columns'])) {
            return false;
        }

        $dto = $this->validator->hydrateDto(StageCode::Listing, $rules);
        if (! $dto instanceof ListingRuleSetDto || $dto->csvDialect === null) {
            return false;
        }

        $table = $this->tabularReader->read($brokenSample, $dto->csvDialect);
        $headers = $table['headers'];
        if ($headers === []) {
            return false;
        }

        $columns = $rules['columns']['columns'] ?? null;
        if (! is_array($columns)) {
            return false;
        }

        $changed = false;
        foreach ($columns as $idx => $column) {
            if (! is_array($column)) {
                continue;
            }
            $aliases = $column['header_aliases'] ?? [];
            if (! is_array($aliases)) {
                continue;
            }

            $stringAliases = array_values(array_filter(
                $aliases,
                static fn (mixed $alias): bool => is_string($alias) && trim($alias) !== '',
            ));
            $exactNormalizedAliases = array_map(
                static fn (string $alias): string => mb_strtolower(trim($alias)),
                $stringAliases,
            );
            $looseNormalizedAliases = array_map(
                fn (string $alias): string => $this->normalizeHeaderLoose($alias),
                $stringAliases,
            );

            foreach ($headers as $header) {
                $exact = mb_strtolower(trim($header));
                if (in_array($exact, $exactNormalizedAliases, true)) {
                    continue;
                }

                $loose = $this->normalizeHeaderLoose($header);
                if ($loose !== '' && in_array($loose, $looseNormalizedAliases, true)) {
                    $stringAliases[] = trim($header);
                    $column['header_aliases'] = array_values(array_unique($stringAliases));
                    $columns[$idx] = $column;
                    $changed = true;
                    break;
                }
            }
        }

        if ($changed) {
            $rules['columns']['columns'] = $columns;
        }

        return $changed;
    }

    private function normalizeHeaderLoose(string $header): string
    {
        $cleaned = str_replace(["\u{00A0}", "\t", "\r", "\n"], ' ', $header);
        $cleaned = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s+/u', ' ', $cleaned) ?? $cleaned;

        return mb_strtolower(trim($cleaned));
    }

    /**
     * @param  array<string, mixed>  $container
     */
    private function prependSelector(array &$container, string $key, string $selector): bool
    {
        $existing = $container[$key] ?? [];
        if (! is_array($existing)) {
            $existing = [];
        }

        if (isset($existing[0]) && $existing[0] === $selector) {
            return false;
        }

        $updated = [$selector];
        foreach ($existing as $item) {
            if (is_string($item) && $item !== $selector) {
                $updated[] = $item;
            }
        }

        $container[$key] = $updated;

        return true;
    }

    private function findRepeatingItemAncestor(DOMElement $node): ?DOMElement
    {
        $current = $node->parentNode;
        while ($current instanceof DOMElement) {
            $tag = strtolower($current->tagName);
            if (in_array($tag, ['tr', 'li', 'div', 'section', 'article'], true)) {
                $parent = $current->parentNode;
                if ($parent instanceof DOMElement) {
                    $sameTagCount = 0;
                    foreach ($parent->childNodes as $sibling) {
                        if ($sibling instanceof DOMElement && strtolower($sibling->tagName) === $tag) {
                            $sameTagCount++;
                        }
                    }
                    if ($sameTagCount >= 1) {
                        return $current;
                    }
                }
            }
            $current = $current->parentNode;
        }

        return null;
    }

    private function findAncestorByTag(DOMNode $node, string $tagName): ?DOMElement
    {
        $target = strtolower($tagName);
        $current = $node->parentNode;
        while ($current instanceof DOMElement) {
            if (strtolower($current->tagName) === $target) {
                return $current;
            }
            $current = $current->parentNode;
        }

        return null;
    }

    private function findSiblingElement(DOMElement $element): ?DOMElement
    {
        $next = $element->nextSibling;
        while ($next !== null) {
            if ($next instanceof DOMElement) {
                return $next;
            }
            $next = $next->nextSibling;
        }

        return null;
    }

    private function buildElementSelector(DOMElement $element): string
    {
        $tag = strtolower($element->tagName);
        $id = trim($element->getAttribute('id'));
        if ($id !== '' && preg_match('/^[A-Za-z_-][A-Za-z0-9_-]*$/', $id) === 1) {
            return $tag.'#'.$id;
        }

        $classAttr = trim($element->getAttribute('class'));
        if ($classAttr !== '') {
            $classes = preg_split('/\s+/', $classAttr) ?: [];
            $validClasses = array_values(array_filter(
                $classes,
                static fn (string $c): bool => $c !== '' && preg_match('/^[A-Za-z_-][A-Za-z0-9_-]*$/', $c) === 1,
            ));

            if ($validClasses !== []) {
                return $tag.'.'.implode('.', array_slice($validClasses, 0, 2));
            }
        }

        return $tag;
    }

    /**
     * @param  list<DriftSignal|array{field_or_selector?: string, expected?: string|null, actual?: string|null, fallback_index?: int}>  $driftSignals
     */
    private function recordDriftEvents(
        ParsingRuleSet $ruleSet,
        int $baselineVersionId,
        array $driftSignals,
        ParsingRuleActorType $actorType,
        ?int $actorId,
    ): void {
        foreach ($driftSignals as $signal) {
            if ($signal instanceof DriftSignal) {
                $this->storeRepository->recordEvent(
                    ruleSetId: $ruleSet->id,
                    ruleVersionId: $baselineVersionId,
                    eventType: ParsingRuleEventType::DriftDetected,
                    actorType: $actorType,
                    actorId: $actorId,
                    reason: sprintf('[%s] %s', $signal->signalCode, $signal->fieldOrSelector),
                    metadata: [
                        'signal_code' => $signal->signalCode,
                        'field_or_selector' => $signal->fieldOrSelector,
                        'expected' => $signal->expected,
                        'actual' => $signal->actual,
                    ],
                );
            } else {
                $field = (string) ($signal['field_or_selector'] ?? 'unknown');
                $this->storeRepository->recordEvent(
                    ruleSetId: $ruleSet->id,
                    ruleVersionId: $baselineVersionId,
                    eventType: ParsingRuleEventType::DriftDetected,
                    actorType: $actorType,
                    actorId: $actorId,
                    reason: sprintf('[fallback] %s', $field),
                    metadata: $signal,
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $candidateRules
     * @param  array<string, string>|list<array{name: string, part: string, body: string, negative?: bool}>  $samples
     * @param  array<string, string>|list<array{name: string, part: string, body: string, negative?: bool}>  $regressionFixtures
     */
    private function verifyAndPersistCandidate(
        ParsingRuleSet $ruleSet,
        ParsingRuleVersion $baselineVersion,
        array $candidateRules,
        array $samples,
        array $regressionFixtures,
        string $changeSummary,
        bool $autoActivate,
        ParsingRuleActorType $actorType,
        ?int $actorId,
        bool $allowModeAutoActivate = true,
    ): ?ParsingRuleVersion {
        $validation = $this->validator->validate(
            stage: $ruleSet->stage,
            rules: $candidateRules,
            schemaVersion: $baselineVersion->schema_version,
            definition: $this->resolvePlatformDefinition($ruleSet->platform_id),
        );
        if (! $validation->valid) {
            return null;
        }

        $candidateDto = $this->validator->hydrateDto($ruleSet->stage, $candidateRules);
        $canonicalRules = $candidateDto->toArray();
        $rulesHash = $this->validator->computeCanonicalHash($ruleSet->stage, $canonicalRules);

        if ($this->readRepository->findByHash($ruleSet->id, $rulesHash) !== null) {
            return null;
        }

        $baselineDto = $this->validator->hydrateDto($ruleSet->stage, $baselineVersion->rules_json);
        $mergedSamples = $this->mergeSamples($samples, $regressionFixtures);
        $report = $this->verifier->verify($candidateDto, $mergedSamples, $baselineDto);

        if (! $report->passed) {
            return null;
        }

        $validationReport = [
            ...$validation->toArray(),
            'dry_run' => $report->toArray(),
        ];

        $version = $this->storeRepository->createVersion(
            ruleSetId: $ruleSet->id,
            schemaId: $validation->schemaId,
            schemaVersion: $validation->schemaVersion,
            rulesJson: $canonicalRules,
            rulesHash: $rulesHash,
            source: ParsingRuleSource::AutoHeal,
            changeSummary: $changeSummary,
            status: ParsingRuleVersionStatus::Validated,
            validationReport: $validationReport,
            parentVersionId: $baselineVersion->id,
            createdBy: $actorId,
            actorType: $actorType,
            actorId: $actorId,
        );

        $this->storeRepository->recordEvent(
            ruleSetId: $ruleSet->id,
            ruleVersionId: $version->id,
            eventType: ParsingRuleEventType::CandidateProposed,
            actorType: $actorType,
            actorId: $actorId,
            reason: $changeSummary,
            metadata: ['rules_hash' => $rulesHash->hex],
        );

        $this->storeRepository->recordEvent(
            ruleSetId: $ruleSet->id,
            ruleVersionId: $version->id,
            eventType: ParsingRuleEventType::CandidateVerified,
            actorType: $actorType,
            actorId: $actorId,
            reason: $this->formatVerificationSummary($report),
            metadata: $report->toArray(),
        );

        $canAutoActivateByParam = $autoActivate && ($allowModeAutoActivate || $regressionFixtures !== []);
        $canAutoActivateByMode = $allowModeAutoActivate && $ruleSet->auto_heal_mode === AutoHealMode::AutoPromoteFallback;
        $shouldAutoActivate = ($canAutoActivateByParam || $canAutoActivateByMode)
            && $report->improved
            && $report->regressions === [];

        if ($shouldAutoActivate) {
            return $this->storeRepository->activateVersion(
                ruleSetId: $ruleSet->id,
                versionId: $version->id,
                actorType: $actorType,
                actorId: $actorId,
                reason: 'Auto-heal: автоматическая активация проверенного кандидата.',
            );
        }

        return $version;
    }

    private function resolvePlatformDefinition(int $platformId): ?PlatformDefinition
    {
        $platformCode = LedgerRow::one('SELECT code FROM prc_platforms WHERE id = ?', [$platformId])?->string('code');
        if ($platformCode === null || trim($platformCode) === '') {
            return null;
        }

        try {
            return app(PlatformRegistry::class)->get(new PlatformCode($platformCode));
        } catch (InvalidPlatformDefinition) {
            return null;
        }
    }

    /**
     * @param  array<string, string>|list<array{name: string, part: string, body: string, negative?: bool}>  $samples
     * @param  array<string, string>|list<array{name: string, part: string, body: string, negative?: bool}>  $regressionFixtures
     * @return list<array{name: string, part: string, body: string, negative: bool}>
     */
    private function mergeSamples(array $samples, array $regressionFixtures): array
    {
        $merged = [];
        foreach ([$samples, $regressionFixtures] as $group) {
            foreach ($group as $key => $item) {
                if (is_string($item)) {
                    $merged[] = [
                        'name' => is_string($key) && $key !== '' ? $key : 'sample_'.(string) $key,
                        'part' => 'default',
                        'body' => $item,
                        'negative' => false,
                    ];
                } else {
                    $merged[] = [
                        'name' => $item['name'],
                        'part' => $item['part'],
                        'body' => $item['body'],
                        'negative' => (bool) ($item['negative'] ?? false),
                    ];
                }
            }
        }

        return $merged;
    }

    private function formatVerificationSummary(DryRunVerificationReport $report): string
    {
        return sprintf(
            'Dry-run: %d/%d пройдено, improved=%s, regressions=%d.',
            $report->passedCount,
            $report->samplesCount,
            $report->improved ? 'yes' : 'no',
            count($report->regressions),
        );
    }
}
