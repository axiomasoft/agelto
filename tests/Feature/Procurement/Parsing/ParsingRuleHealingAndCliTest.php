<?php

declare(strict_types=1);

use App\Dto\Procurement\Parsing\Extraction\DriftSignal;
use App\Dto\Procurement\Parsing\Rules\DetailRuleSetDto;
use App\Dto\Procurement\Parsing\Rules\ListingRuleSetDto;
use App\Enums\Procurement\Parsing\AutoHealMode;
use App\Enums\Procurement\Parsing\ParsingRuleEventType;
use App\Enums\Procurement\Parsing\ParsingRuleSource;
use App\Enums\Procurement\Parsing\ParsingRuleVersionStatus;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Guards\Admin\Permissions\ParsingRuleSetPermission;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Parsing\ParsingRuleEvent;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Parsing\ParsingRuleReadRepository;
use App\Services\Procurement\Parsing\Healing\ParsingRuleAutoHealer;
use App\Services\Procurement\Parsing\Healing\ParsingRuleDryRunVerifier;
use App\Services\Procurement\Parsing\ParsingRuleManager;

use function Pest\Laravel\artisan;

function detailRulesPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'format' => 'html',
        'dom' => [
            'section_selectors' => [
                'common_info' => ['.broken-section', '.info-section'],
            ],
            'field_rows_selectors' => ['.broken-row', '.info-row'],
            'label_selectors' => ['.broken-label', '.info-label'],
            'value_selectors' => ['.broken-value', '.info-value'],
        ],
        'documents' => [
            'container_selectors' => ['.old-docs'],
            'item_selectors' => ['.old-doc-item'],
            'title_selectors' => ['.old-doc-title'],
            'url_selectors' => ['.old-doc-url'],
        ],
        'event_journal' => [
            'row_selectors' => ['.old-events tr'],
            'date_selectors' => ['td.old-date'],
            'description_selectors' => ['td.old-desc'],
            'classifications' => [],
        ],
    ], $overrides);
}

function csvListingRulesPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'format' => 'csv',
        'transport' => [
            'method' => 'GET',
            'page_param' => 'page',
            'page_size_param' => 'size',
            'default_page_size' => 50,
        ],
        'date_parsing' => [
            'formats' => ['d.m.Y'],
            'timezone' => 'Europe/Moscow',
        ],
        'csv_dialect' => [
            'delimiter' => ';',
            'enclosure' => '"',
            'escape' => '\\',
            'encoding' => 'UTF-8',
            'strip_bom' => true,
            'skip_empty_rows' => true,
            'header_row_index' => 0,
        ],
        'columns' => [
            'columns' => [
                [
                    'target_field' => 'external_id',
                    'header_aliases' => ['Номер закупки'],
                    'required' => true,
                ],
            ],
        ],
    ], $overrides);
}

test('верификатор dry-run проверяет сэмплы, сравнивает с baseline и отклоняет негативные ложные срабатывания', function (): void {
    $verifier = app(ParsingRuleDryRunVerifier::class);

    $baseline = DetailRuleSetDto::fromArray(detailRulesPayload([
        'documents' => [
            'container_selectors' => ['.missing-container'],
            'item_selectors' => ['.doc-item'],
            'title_selectors' => ['a'],
            'url_selectors' => ['a'],
        ],
    ]));

    $candidate = DetailRuleSetDto::fromArray(detailRulesPayload([
        'documents' => [
            'container_selectors' => ['.docs-wrap'],
            'item_selectors' => ['.doc-item'],
            'title_selectors' => ['a'],
            'url_selectors' => ['a'],
        ],
    ]));

    $samples = [
        [
            'name' => 'docs_ok',
            'part' => 'documents',
            'body' => '<div class="docs-wrap"><div class="doc-item"><a href="/filestore/1">Смета.pdf</a></div></div>',
            'negative' => false,
        ],
        [
            'name' => 'login_page',
            'part' => 'documents',
            'body' => '<html><body><form><input type="password" /></form></body></html>',
            'negative' => true,
        ],
    ];

    $report = $verifier->verify($candidate, $samples, $baseline);

    expect($report->passed)->toBeTrue()
        ->and($report->improved)->toBeTrue()
        ->and($report->regressions)->toBe([])
        ->and($report->samplesCount)->toBe(2)
        ->and($report->passedCount)->toBe(2);
});

test('верификатор dry-run отклоняет сэмпл превышающий лимит 5 МБ', function (): void {
    $verifier = app(ParsingRuleDryRunVerifier::class);
    $rules = ListingRuleSetDto::fromArray(csvListingRulesPayload());

    $report = $verifier->verify(
        $rules,
        ['huge' => str_repeat('a', ParsingRuleDryRunVerifier::MAX_SAMPLE_BYTES + 1)],
    );

    expect($report->passed)->toBeFalse()
        ->and($report->passedCount)->toBe(0)
        ->and($report->samples[0]->errors[0])->toContain('5 МБ');
});

test('auto-heal продвигает fallback-селектор на позицию 0, дедуплицирует по hash и автоактивирует только при разрешении', function (): void {
    $platform = Platform::factory()->create();
    $manager = app(ParsingRuleManager::class);
    $healer = app(ParsingRuleAutoHealer::class);

    $active = $manager->publish(
        platformId: $platform->id,
        stage: StageCode::Detail,
        rules: detailRulesPayload([
            'dom' => [
                'field_rows_selectors' => ['.broken-row', '.real-row'],
                'label_selectors' => ['.real-label'],
                'value_selectors' => ['.real-value'],
            ],
        ]),
        changeSummary: 'Initial',
        autoHealMode: AutoHealMode::Propose,
    );

    $ruleSet = $active->ruleSet->fresh();
    $html = '<div class="info-section"><div class="real-row"><span class="real-label">Статус</span><span class="real-value">Подача заявок</span></div></div>';

    $signals = [
        new DriftSignal('fallback_used', StageCode::Detail, 'dom.field_rows_selectors', '.broken-row', '.real-row'),
    ];
    $samples = [
        ['name' => 'common', 'part' => 'common-info', 'body' => $html, 'negative' => false],
    ];

    // В режиме Propose без autoActivate — создаётся validated, но не активируется.
    $proposed = $healer->proposeFallbackPromotion($ruleSet, $signals, $samples, autoActivate: false);
    expect($proposed)->not->toBeNull()
        ->and($proposed?->status)->toBe(ParsingRuleVersionStatus::Validated)
        ->and($proposed?->source)->toBe(ParsingRuleSource::AutoHeal)
        ->and($proposed?->rules_json['dom']['field_rows_selectors'][0])->toBe('.real-row');

    // Повторный вызов с тем же набором должен дедуплицироваться по rules_hash.
    $duplicate = $healer->proposeFallbackPromotion($ruleSet->fresh(), $signals, $samples, autoActivate: true);
    expect($duplicate)->toBeNull();

    $events = ParsingRuleEvent::query()->where('rule_set_id', $ruleSet->id)->pluck('event_type')->all();
    expect($events)->toContain(
        ParsingRuleEventType::DriftDetected,
        ParsingRuleEventType::CandidateProposed,
        ParsingRuleEventType::CandidateVerified,
    );
});

test('auto-heal детерминированно находит структурных кандидатов для documents, event-journal, common-info и CSV listing', function (): void {
    $platform = Platform::factory()->create();
    $manager = app(ParsingRuleManager::class);
    $healer = app(ParsingRuleAutoHealer::class);

    $detailActive = $manager->publish(
        platformId: $platform->id,
        stage: StageCode::Detail,
        rules: detailRulesPayload(),
        changeSummary: 'Initial detail',
        autoHealMode: AutoHealMode::AutoPromoteFallback,
    );
    $detailSet = $detailActive->ruleSet->fresh();

    // 1. documents по a[href*="filestore"] (без regressionFixtures и autoActivate не автоактивируется)
    $docsHtml = '<section class="new-docs"><div class="doc-card"><span><a href="/filestore/download/123">Договор.pdf</a></span></div></section>';
    $docsCandidate = $healer->discoverCandidates($detailSet, $docsHtml, 'documents');
    expect($docsCandidate)->not->toBeNull()
        ->and($docsCandidate?->status)->toBe(ParsingRuleVersionStatus::Validated);

    // 2. event-journal по span.timeZoneName (с autoActivate: true и regressionFixtures автоактивируется)
    $eventsHtml = '<table class="journal-tbl"><tbody><tr><td><span class="timeZoneName">01.10.2026 10:00</span></td><td>Извещение размещено</td></tr></tbody></table>';
    $eventsCandidate = $healer->discoverCandidates(
        ruleSet: $detailSet->fresh(),
        brokenSample: $eventsHtml,
        part: 'event-journal',
        regressionFixtures: [
            [
                'name' => 'negative-empty',
                'part' => 'event-journal',
                'body' => '<html><body><div class="empty">Нет данных</div></body></html>',
                'negative' => true,
            ],
        ],
        autoActivate: true,
    );
    expect($eventsCandidate)->not->toBeNull()
        ->and($eventsCandidate?->status)->toBe(ParsingRuleVersionStatus::Active);

    // 3. common-info по статической метке
    $infoHtml = '<div class="card-block"><div class="kv-item"><div class="kv-key">Идентификационный код закупки (ИКЗ)</div><div class="kv-val">261000000000000000000000000000000000</div></div></div>';
    $infoCandidate = $healer->discoverCandidates($detailSet->fresh(), $infoHtml, 'common-info');
    expect($infoCandidate)->not->toBeNull()
        ->and($infoCandidate?->status)->toBe(ParsingRuleVersionStatus::Validated);

    // 4. CSV listing по переименованному заголовку (whitespace/case/punctuation)
    $listingActive = $manager->publish(
        platformId: $platform->id,
        stage: StageCode::Listing,
        rules: csvListingRulesPayload(),
        changeSummary: 'Initial listing',
        autoHealMode: AutoHealMode::AutoPromoteFallback,
    );
    $csvBroken = "  номер   закупки: \n1234567890\n";
    $listingCandidate = $healer->discoverCandidates($listingActive->ruleSet->fresh(), $csvBroken);
    expect($listingCandidate)->not->toBeNull()
        ->and($listingCandidate?->status)->toBe(ParsingRuleVersionStatus::Validated);
});

test('6 ленивых Artisan-команд выполняют полный цикл validate, publish, history, export, rollback и auto-heal', function (): void {
    $platform = Platform::factory()->create(['code' => 'cli_test_platform']);
    $tmpDir = sys_get_temp_dir().'/parsing_cli_'.uniqid();
    mkdir($tmpDir, 0777, true);

    $rulesFile = $tmpDir.'/rules.json';
    $rulesV2File = $tmpDir.'/rules_v2.json';
    $sampleCsv = $tmpDir.'/sample.csv';
    $brokenCsv = $tmpDir.'/broken.csv';

    file_put_contents($rulesFile, json_encode(csvListingRulesPayload(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    file_put_contents($rulesV2File, json_encode(csvListingRulesPayload([
        'transport' => ['default_page_size' => 100],
    ]), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    file_put_contents($sampleCsv, "Номер закупки\n12345\n");
    file_put_contents($brokenCsv, "  номер  закупки \n98765\n");

    try {
        artisan('parsing-rules:validate', [
            'platform' => $platform->code,
            'stage' => 'listing',
            '--file' => $rulesFile,
            '--sample' => [$sampleCsv],
        ])->assertSuccessful();

        artisan('parsing-rules:publish', [
            'platform' => $platform->code,
            'stage' => 'listing',
            '--file' => $rulesFile,
            '--reason' => 'Initial CLI publish',
            '--sample' => [$sampleCsv],
        ])->assertSuccessful();

        artisan('parsing-rules:publish', [
            'platform' => $platform->code,
            'stage' => 'listing',
            '--file' => $rulesV2File,
            '--reason' => 'Second CLI publish',
        ])->assertSuccessful();

        artisan('parsing-rules:history', [
            'platform' => $platform->code,
            'stage' => 'listing',
        ])->assertSuccessful();

        artisan('parsing-rules:export', [
            'platform' => $platform->code,
            'stage' => 'listing',
        ])->assertSuccessful();

        artisan('parsing-rules:export', [
            'platform' => $platform->code,
            'stage' => 'listing',
            '--schema' => true,
        ])->assertSuccessful();

        artisan('parsing-rules:rollback', [
            'platform' => $platform->code,
            'stage' => 'listing',
            '--to' => '1',
            '--reason' => 'Rollback to v1',
        ])->assertSuccessful();

        $ruleSet = app(ParsingRuleReadRepository::class)->findRuleSet($platform->id, StageCode::Listing);
        expect($ruleSet?->activeVersion?->version)->toBe(3)
            ->and($ruleSet?->activeVersion?->rules_json['transport']['default_page_size'] ?? null)->toBe(50);

        artisan('parsing-rules:auto-heal', [
            'platform' => $platform->code,
            'stage' => 'listing',
            '--sample' => $brokenCsv,
            '--regression' => [$sampleCsv],
            '--activate' => true,
        ])->assertSuccessful();

        expect($ruleSet?->fresh()?->activeVersion?->version)->toBe(4);
    } finally {
        @unlink($rulesFile);
        @unlink($rulesV2File);
        @unlink($sampleCsv);
        @unlink($brokenCsv);
        @rmdir($tmpDir);
    }
});

test('роль оператора имеет только чтение и создание черновиков правил парсинга, без Update, Activate, Rollback и AutoHeal', function (): void {
    $permissions = (new OperatorRole)->permissions();

    expect($permissions)->toContain(
        ParsingRuleSetPermission::ViewAny,
        ParsingRuleSetPermission::View,
        ParsingRuleSetPermission::Create,
    )->not->toContain(
        ParsingRuleSetPermission::Update,
        ParsingRuleSetPermission::Activate,
        ParsingRuleSetPermission::Rollback,
        ParsingRuleSetPermission::AutoHeal,
    );
});
