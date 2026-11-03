<?php

declare(strict_types=1);

use App\Dto\Procurement\Acquisition\DetailFetched;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Acquisition\ListingFetched;
use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Platform\SourceIdentifierType;
use App\Dto\Procurement\Platform\SourceIdentifierValue;
use App\Dto\Procurement\Platform\SourceIdentity;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\StageBlocked;
use App\Enums\Procurement\Network\AdapterVerdict;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Platform\FailureScope;
use App\Enums\Procurement\Platform\TerminalProof;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\ControlMode;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Process\ControlRepository;
use App\Services\Procurement\Acquisition\AcquisitionAssessment;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Doubles\Crawler\CardHtmlFixture;
use Tests\Support\ProcessCore\DetailFixtures;
use Tests\Support\ProcessCore\Http\AcquisitionWireFixture;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;

beforeEach(fn () => F::clean());
afterEach(fn () => F::clean());

/** Production source with real saved registry URLs and real wire bodies; no logical-call double. */
function adapterCardInput(AcquisitionWireFixture $fixture, bool $completeResults = true): Procurement
{
    $wire = $fixture->wire;
    $registry = $fixture->registry;
    config(['documents.allowed_hosts' => ['fixture.test', 'zakupki.gov.ru']]);
    $registry->update(['settings' => [
        'procedure_url_types' => ['Электронный аукцион' => 'ea20'],
        'card_url_templates' => ['ea20' => [
            'common-info' => $wire->url('/common-info?regNumber={regNumber}')->reveal(),
            'documents' => $wire->url('/documents?regNumber={regNumber}')->reveal(),
            'event-journal' => $wire->url('/event-journal?regNumber={regNumber}')->reveal(),
            'supplier-results' => $wire->url('/supplier-results?regNumber={regNumber}')->reveal(),
        ]],
    ]]);
    $number = '0356500001426005498';
    foreach (['common-info' => CardHtmlFixture::commonInfo(), 'documents' => CardHtmlFixture::documents(),
        'event-journal' => CardHtmlFixture::eventJournal(), 'supplier-results' => $completeResults
            ? file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/supplier-results-success.html'))
            : CardHtmlFixture::supplierResults()] as $tab => $body) {
        // Historical parser fragments omit the card header; supply the explicit identity
        // envelope required by the full adapter, keeping all original section bytes.
        $header = '<a class="cardMainInfo__purchaseLink">№ '.$number.'</a>'
            .'<div class="cardMainInfo__purchaseObject">Поставка бумаги офисной</div>'
            .'<div class="cardMainInfo__state">Подача заявок</div>';
        $wire->respond('/'.$tab, $tab === 'supplier-results' && $completeResults ? $body : str_replace('<body>', '<body>'.$header, $body), headers: ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    DetailFixtures::bind();
    $detail = DetailFixtures::work($registry, $number);
    $run = DB::table('prc_process_work')->where('id', $detail['work']->value->value)->value('origin_run_id');
    DB::table('prc_process_runs')->where('id', $run)->update([
        'configuration_snapshot' => json_encode(['profile_version' => 1, 'route_profile_id' => $fixture->routeProfile->value,
            'route_policy_version' => 1, 'kind' => 'fresh', 'initial_from' => '2026-01-01T00:00:00+00:00',
            'through' => '2026-01-01T23:59:59+00:00'], JSON_THROW_ON_ERROR),
        'configuration_schema_version' => 1,
    ]);

    return $detail['p'];
}

function adapterCardRequest(Procurement $procurement): DetailRequest
{
    return new DetailRequest('fz44', new SourceIdentity(new PlatformCode('eis'),
        new SourceIdentifierType('registry:44fz'), new SourceIdentifierValue($procurement->source_identity_value)),
        new CursorEnvelope('eis.card', 1, 'ea20'));
}

test('настоящая карточка проходит все вкладки и pure parsers под одним owner до full success', function (): void {
    $wire = new WireFixture;
    try {
        $fixture = new AcquisitionWireFixture($wire);
        $procurement = adapterCardInput($fixture);
        $before = $procurement->refresh()->getRawOriginal();
        $details = $fixture->fetch(adapterCardRequest($procurement));
        expect(collect($details->value->snapshot->parts)->mapWithKeys(fn ($part) => [$part->name => $part->completeness->value])->all())
            ->toBe(['parties' => 'complete', 'attributes' => 'complete', 'documents' => 'complete', 'events' => 'complete', 'results' => 'complete']);
        expect($details->value)->toBeInstanceOf(DetailFetched::class)
            ->and($details->receipt->verdict)->toBe(AdapterVerdict::Accepted)
            ->and($details->value->snapshot->complete())->toBeTrue()
            ->and($details->value->snapshot->parties)->not->toBeEmpty()
            ->and($details->value->snapshot->documents)->not->toBeEmpty()
            ->and($details->receipt->requestIds)->toHaveCount(4)
            ->and(DB::table('prc_outbound_operations')->count())->toBe(1)
            ->and(DB::table('prc_network_permits')->where('state', 'released')->count())->toBe(4)
            ->and(DB::table('prc_adapter_health')->where('capability', 'detail')->sole()->state)->toBe('healthy')
            ->and($procurement->refresh()->getRawOriginal())->toBe($before);
    } finally {
        $wire->stop();
    }
})->group('t03', 'p26');

test('усечённый исторический supplier-results не превращает transport success в accepted snapshot', function (): void {
    $wire = new WireFixture;
    try {
        $fixture = new AcquisitionWireFixture($wire);
        $procurement = adapterCardInput($fixture, completeResults: false);
        $before = $procurement->refresh()->getRawOriginal();
        $details = $fixture->fetch(adapterCardRequest($procurement));
        $results = collect($details->value->snapshot->parts)->firstWhere('name', 'results');
        expect($details->value)->toBeInstanceOf(DetailFetched::class)
            ->and($details->receipt->verdict)->toBe(AdapterVerdict::Partial)
            ->and($details->value->snapshot->complete())->toBeFalse()
            ->and($results->completeness->value)->toBe('partial')
            ->and($details->receipt->requestIds)->toHaveCount(4)
            ->and($procurement->refresh()->getRawOriginal())->toBe($before);
    } finally {
        $wire->stop();
    }
});

test('process diagnose исполняет полный canary при acquisition pause без domain writes и cursor advance', function (): void {
    $wire = new WireFixture;
    try {
        $fixture = new AcquisitionWireFixture($wire);
        $procurement = adapterCardInput($fixture);
        $before = $procurement->refresh()->getRawOriginal();
        $control = DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->sole();
        app(ProcessTransaction::class)->run(fn () => app(ControlRepository::class)->setMode('acquisition', ControlMode::Paused,
            null, 'Приёмка диагностического canary', $control->control_version, LedgerFixtures::uuid(), LedgerFixtures::uuid()));
        $runsBefore = DB::table('prc_process_runs')->get()->toJson();
        $exit = Artisan::call('process:diagnose', ['platform' => 'eis', '--adapter' => 'detail', '--procurement-id' => (string) $procurement->id]);
        $this->assertSame(0, $exit, Artisan::output());
        expect($wire->receipts())->toHaveCount(4)
            ->and(DB::table('prc_network_demands')->distinct()->pluck('traffic_class')->all())->toBe(['diagnostic'])
            ->and(DB::table('prc_adapter_health')->where('capability', 'detail')->sole()->state)->toBe('healthy')
            ->and(DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->value('desired_mode'))->toBe('paused')
            ->and(DB::table('prc_process_runs')->get()->toJson())->toBe($runsBefore)
            ->and($procurement->refresh()->getRawOriginal())->toBe($before);
    } finally {
        DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->update(['desired_mode' => 'enabled']);
        $wire->stop();
    }
})->group('t03', 'p26');

test('частично битый CSV сохраняет mapped output и partial receipt без ложного adapter hold', function (): void {
    $wire = new WireFixture;
    try {
        $fixture = new AcquisitionWireFixture($wire);
        $body = rtrim(file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Csv/neutral-valid.csv')), "\r\n")."\nonly-one-cell\n";
        $wire->respond('/csv', $body, headers: ['Content-Type' => 'text/csv; charset=UTF-8']);
        $batch = $fixture->fetch($fixture->listingRequest());
        // P2.18 publishes a block atomically: malformed records retain factual cardinality,
        // but the page cannot advance its cursor or expose a partially mapped canonical block.
        expect($batch->value)->toBeInstanceOf(ListingFetched::class)
            ->and($batch->value->page->rowsTotal)->toBe(2)
            ->and($batch->value->page->summaries->items)->toBe([])
            ->and($batch->value->page->nextCursor)->toBeNull()
            ->and($batch->value->page->terminalProof)->toBe(TerminalProof::None)
            ->and($batch->value->page->assessment->reason)->toBe(AssessmentReason::SchemaMismatch)
            ->and($batch->value->page->assessment->failureScope)->toBe(FailureScope::Item)
            ->and($batch->receipt->verdict)->toBe(AdapterVerdict::Partial)
            ->and(DB::table('prc_procurements')->count())->toBe(0)
            ->and(DB::table('prc_adapter_health')->where('capability', 'listing')->sole()->state)->toBe('validating');
        $assessment = new AcquisitionAssessment;
        $assessment->observe($batch->value);
        expect($assessment->inspection(new StageBlocked(BlockedReason::HandlerHold), ContentHash::of('listing-contract'))->verdict)
            ->toBe(AdapterVerdict::Partial);
    } finally {
        $wire->stop();
    }
})->group('t03', 'p26');

test('CSV без подтверждённого контракта остаётся adapter failure без публикации блока', function (string $failure): void {
    $wire = new WireFixture;
    try {
        $fixture = new AcquisitionWireFixture($wire);
        $valid = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Csv/neutral-valid.csv'));
        $body = match ($failure) {
            'header' => str_replace('Реестровый номер закупки', 'Неизвестный столбец', $valid),
            'all_rows' => explode("\n", $valid)[0]."\nonly-one-cell\n",
        };
        $wire->respond('/csv', $body, headers: ['Content-Type' => 'text/csv; charset=UTF-8']);
        $batch = $fixture->fetch($fixture->listingRequest());
        expect($batch->value)->toBeInstanceOf(ListingFetched::class)
            ->and($batch->value->page->assessment->failureScope)->toBe(FailureScope::Adapter)
            ->and($batch->value->page->assessment->reason)->toBe(AssessmentReason::SchemaMismatch)
            ->and($batch->value->page->summaries->items)->toBe([])
            ->and($batch->value->page->nextCursor)->toBeNull()
            ->and($batch->value->page->terminalProof)->toBe(TerminalProof::None)
            ->and($batch->receipt->verdict)->toBe(AdapterVerdict::ContractFailure)
            ->and(DB::table('prc_procurements')->count())->toBe(0);
        $assessment = new AcquisitionAssessment;
        $assessment->observe($batch->value);
        expect($assessment->inspection(new StageBlocked(BlockedReason::HandlerHold), ContentHash::of('listing-contract'))->verdict)
            ->toBe(AdapterVerdict::ContractFailure);
    } finally {
        $wire->stop();
    }
})->with(['header', 'all_rows'])->group('t03', 'p26');
