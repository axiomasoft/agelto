<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingCursor;
use App\Enums\Procurement\Network\AdapterVerdict;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Process\ControlRepository;
use App\Services\Platforms\Eis\Csv\CsvSourceMethod;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;
use Tests\Support\Doubles\Crawler\CardHtmlFixture;
use Tests\Support\Platforms\Eis\EisCsvFixture;
use Tests\Support\ProcessCore\Http\CallerFixtures as C;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;

beforeEach(fn () => F::clean());
afterEach(fn () => F::clean());

/** Production source with real saved registry URLs and real wire bodies; no logical-call double. */
function adapterCardInput(WireFixture $wire): Procurement
{
    $registry = C::platform($wire);
    config(['crawler.documents_allowed_hosts' => ['fixture.test', 'zakupki.gov.ru']]);
    $registry->update(['settings' => [
        'procedure_url_types' => ['Электронный аукцион' => 'ea20'],
        'card_url_templates' => ['ea20' => [
            'common-info' => $wire->url('/common-info?regNumber={regNumber}')->reveal(),
            'documents' => $wire->url('/documents?regNumber={regNumber}')->reveal(),
            'event-journal' => $wire->url('/event-journal?regNumber={regNumber}')->reveal(),
            'supplier-results' => $wire->url('/supplier-results?regNumber={regNumber}')->reveal(),
        ]],
    ]]);
    $number = '0338200009125000001';
    foreach (['common-info' => CardHtmlFixture::commonInfo(), 'documents' => CardHtmlFixture::documents(),
        'event-journal' => CardHtmlFixture::eventJournal(), 'supplier-results' => CardHtmlFixture::supplierResults()] as $tab => $body) {
        $wire->respond('/'.$tab, $body, headers: ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    return Procurement::query()->firstOrCreate(['registry_id' => $registry->id, 'registry_number' => $number],
        Procurement::factory()->make(['registry_id' => $registry->id, 'registry_number' => $number,
            'procedure_type_raw' => 'Электронный аукцион', 'status' => ProcurementStatus::Awarded])->getAttributes());
}

test('настоящая карточка проходит все вкладки и pure parsers под одним owner до full success', function (): void {
    $wire = new WireFixture;
    try {
        $procurement = adapterCardInput($wire);
        $before = $procurement->refresh()->getRawOriginal();
        $details = app(CsvSourceMethod::class)->collectDetails($procurement->registry_number, $procurement->registry);
        expect($details->parsed)->not->toBeNull()->and($details->validation->verdict)->toBe(AdapterVerdict::Accepted)
            ->and($details->parsed->commonInfo->authorizesCommonInfoReconciliation())->toBeTrue()
            ->and($details->parsed->documents->authorizesReconciliation())->toBeTrue()
            ->and($details->validation->requestIds)->toHaveCount(4)
            ->and(DB::table('prc_outbound_operations')->count())->toBe(1)
            ->and(DB::table('prc_network_permits')->where('state', 'released')->count())->toBe(4)
            ->and(DB::table('prc_adapter_health')->where('capability', 'detail')->sole()->state)->toBe('healthy')
            ->and($procurement->refresh()->getRawOriginal())->toBe($before);
    } finally {
        $wire->stop();
    }
})->group('t03', 'p26');

test('process diagnose исполняет полный canary при acquisition pause без domain writes и cursor advance', function (): void {
    $wire = new WireFixture;
    try {
        $procurement = adapterCardInput($wire);
        $before = $procurement->refresh()->getRawOriginal();
        $control = DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->sole();
        app(ProcessTransaction::class)->run(fn () => app(ControlRepository::class)->setMode('acquisition', ControlMode::Paused,
            null, 'Приёмка диагностического canary', $control->control_version, LedgerFixtures::uuid(), LedgerFixtures::uuid()));
        $runsBefore = DB::table('prc_crawler_runs')->get()->toJson();
        $this->artisan('process:diagnose', ['platform' => 'eis', '--adapter' => 'detail', '--procurement-id' => (string) $procurement->id])
            ->assertSuccessful();
        expect($wire->receipts())->toHaveCount(4)
            ->and(DB::table('prc_network_demands')->distinct()->pluck('traffic_class')->all())->toBe(['diagnostic'])
            ->and(DB::table('prc_adapter_health')->where('capability', 'detail')->sole()->state)->toBe('healthy')
            ->and(DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->value('desired_mode'))->toBe('paused')
            ->and(DB::table('prc_crawler_runs')->get()->toJson())->toBe($runsBefore)
            ->and($procurement->refresh()->getRawOriginal())->toBe($before);
    } finally {
        DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->update(['desired_mode' => 'enabled']);
        $wire->stop();
    }
})->group('t03', 'p26');

test('частично битый CSV сохраняет mapped output и partial receipt без ложного adapter hold', function (): void {
    $wire = new WireFixture;
    try {
        $registry = C::platform($wire);
        Okpd2Node::query()->firstOrCreate(['platform_id' => $registry->platform_id, 'code' => '43'], Okpd2Node::factory()->make(['platform_id' => $registry->platform_id, 'code' => '43', 'external_id' => '8873921'])->getAttributes());
        $crawler = Crawler::factory()->for($registry)->create();
        $body = rtrim(EisCsvFixture::body(), "\r\n")."\nonly-one-cell\n";
        $wire->respond('/csv', $body, headers: ['Content-Type' => 'text/csv; charset=windows-1251']);
        $batch = app(CsvSourceMethod::class)->collectListing($crawler, new ListingCursor(blockFrom: 1));
        expect($batch->rowsTotal)->toBe(5)->and($batch->rowsMalformed)->toBe(1)
            ->and($batch->mapping->snapshots)->toHaveCount(4)
            ->and($batch->validation->verdict)->toBe(AdapterVerdict::Partial)
            ->and(DB::table('prc_adapter_health')->where('capability', 'listing')->sole()->state)->toBe('validating');
    } finally {
        $wire->stop();
    }
})->group('t03', 'p26');
