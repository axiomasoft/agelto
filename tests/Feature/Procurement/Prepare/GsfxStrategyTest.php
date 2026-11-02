<?php

declare(strict_types=1);

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\PreparationResult;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\ProcurementEvent;
use App\Services\Procurement\Prepare\DocumentFormatInspector;
use App\Services\Procurement\Prepare\DocumentFormatRegistry;
use App\Services\Procurement\Prepare\Gsfx\GsfxReader;
use App\Services\Procurement\Prepare\GsfxStrategy;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\StoredDocumentFixtures;

beforeEach(fn () => StoredDocumentFixtures::reset());

/**
 * `.gsfx` → структурная модель и текст (P3.3, D2). Синтетический контейнер собирается в тесте
 * из `tests/Fixtures/gsfx/{Properties.txt,Data.xml}` (реальный 6.2-МБ эталон в git не коммитится,
 * D1) — те же файлы, перекодированные в cp1251, что и настоящий контейнер ГрандСметы.
 */
function buildGsfxZip(array $extraEntries = []): string
{
    $zipPath = tempnam(sys_get_temp_dir(), 'gsfx_test_').'.gsfx';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFile(base_path('tests/Fixtures/gsfx/Properties.txt'), 'Properties.txt');
    $zip->addFile(base_path('tests/Fixtures/gsfx/Data.xml'), 'Data.xml');

    foreach ($extraEntries as $name => $content) {
        $zip->addFromString($name, $content);
    }

    $zip->close();

    return $zipPath;
}

function makeGsfxVersion(string $zipPath): array
{
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $disk = Storage::fake((string) config('crawler.documents_disk'));
    $storagePath = 'documents/gsfx-test.gsfx';
    $disk->put($storagePath, file_get_contents($zipPath));

    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'extension' => 'gsfx',
        'disk' => (string) config('crawler.documents_disk'),
        'storage_path' => $storagePath,
        'content_hash' => hash('sha256', $storagePath),
    ]);

    return [$document, $version];
}

test('паспорт разобран из cp1251 без мусора', function () {
    [$document, $version] = makeGsfxVersion(buildGsfxZip());

    app(GsfxStrategy::class)->process($document, $version);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::StructuredXml)
        ->first();

    $disk = Storage::disk((string) config('crawler.documents_disk'));
    $decoded = json_decode($disk->get($representation->storage_path), true);

    expect($decoded['passport'])->toBe([
        'doc_type' => 'sdtSmeta',
        'loc_num' => 'ЛСР-ТЕСТ-01',
        'object_name' => 'Тестовый объект — ремонт помещения',
        'total' => '12345.67',
        'calc_method' => 'Res',
        'normative_base' => 'ФСНБ-2022 (с Изм. 1-13)',
        'program_version' => '15.3.5.55343',
        'modified_at' => '05.02.2026 19:17:02',
    ]);
});

test('позиция несёт обе цены', function () {
    [$document, $version] = makeGsfxVersion(buildGsfxZip());

    app(GsfxStrategy::class)->process($document, $version);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::StructuredXml)
        ->first();

    $disk = Storage::disk((string) config('crawler.documents_disk'));
    $decoded = json_decode($disk->get($representation->storage_path), true);

    $position = $decoded['chapters'][0]['positions'][1];
    expect($position['name'])->toBe('Раствор готовый кладочный')
        ->and($position['price_base'])->toBe('3404,62')
        ->and($position['price_curr'])->toBe('6741,15');
});

test('ресурсы привязаны к позиции', function () {
    [$document, $version] = makeGsfxVersion(buildGsfxZip());

    app(GsfxStrategy::class)->process($document, $version);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::StructuredXml)
        ->first();

    $disk = Storage::disk((string) config('crawler.documents_disk'));
    $decoded = json_decode($disk->get($representation->storage_path), true);

    $resources = $decoded['chapters'][0]['positions'][0]['resources'];
    expect($resources)->toHaveCount(2)
        ->and($resources[0])->toMatchArray([
            'resource_type' => 'Mat',
            'name' => 'Вода',
            'code' => '01.7.03.01-0001',
            'unit' => 'м3',
            'quantity' => '3,5',
            'price_base' => '35,71',
            'price_curr' => '62,49',
            'index_code' => '2',
        ])
        ->and($decoded['chapters'][0]['positions'][0]['indices'])->toBe(['2', '1133']);
});

test('unmapped заполняется элементами и атрибутами, не попавшими в модель', function () {
    [$document, $version] = makeGsfxVersion(buildGsfxZip());

    app(GsfxStrategy::class)->process($document, $version);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::StructuredXml)
        ->first();

    expect($representation->meta['unmapped'])->toMatchArray([
        'SimilarResGroups' => 1,
        'Header' => 1,
        'WorksList' => 1,
        'Document@ProgramVersion' => 1,
        'Document@Generator' => 1,
        'Position@SysID' => 2,
        'Quantity@Fx' => 2,
        'Quantity@Precision' => 2,
        'Indexes@IndexesMode' => 1,
        'Indexes@IndexesLinkMode' => 1,
        'Vid_Rab@NaclMask' => 1,
    ]);
});

test('позиция с IndexCode несёт index_code, позиция без него — null', function () {
    [$document, $version] = makeGsfxVersion(buildGsfxZip());

    app(GsfxStrategy::class)->process($document, $version);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::StructuredXml)
        ->first();

    $disk = Storage::disk((string) config('crawler.documents_disk'));
    $decoded = json_decode($disk->get($representation->storage_path), true);

    $positions = $decoded['chapters'][0]['positions'];
    expect($positions[0]['index_code'])->toBeNull()
        ->and($positions[1]['index_code'])->toBe('5');
});

test('каталог индексов разобран в две раздельные карты, одинаковый Code в разных подкаталогах даёт разные записи', function () {
    [$document, $version] = makeGsfxVersion(buildGsfxZip());

    app(GsfxStrategy::class)->process($document, $version);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::StructuredXml)
        ->first();

    $disk = Storage::disk((string) config('crawler.documents_disk'));
    $decoded = json_decode($disk->get($representation->storage_path), true);

    $catalog = $decoded['index_catalog'];
    expect($catalog['positions']['9'])->toMatchArray(['code' => '9', 'mt' => '1,50', 'em' => null])
        ->and($catalog['resources']['9'])->toMatchArray(['code' => '9', 'mt' => null, 'em' => '3,10']);
});

test('запись каталога индексов без MT и EM несёт оба поля null', function () {
    [$document, $version] = makeGsfxVersion(buildGsfxZip());

    app(GsfxStrategy::class)->process($document, $version);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::StructuredXml)
        ->first();

    $disk = Storage::disk((string) config('crawler.documents_disk'));
    $decoded = json_decode($disk->get($representation->storage_path), true);

    expect($decoded['index_catalog']['positions']['7'])->toMatchArray(['mt' => null, 'em' => null]);
});

test('непрочитанный атрибут открытого узла попал в unmapped ключом Тег@Атрибут с частотой', function () {
    [$document, $version] = makeGsfxVersion(buildGsfxZip());

    app(GsfxStrategy::class)->process($document, $version);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::StructuredXml)
        ->first();

    expect($representation->meta['unmapped']['Position@SysID'])->toBe(2)
        ->and($representation->meta['unmapped']['Quantity@Fx'])->toBe(2);
});

test('атрибуты неоткрываемого элемента в unmapped не разворачиваются', function () {
    [$document, $version] = makeGsfxVersion(buildGsfxZip());

    app(GsfxStrategy::class)->process($document, $version);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::StructuredXml)
        ->first();

    expect($representation->meta['unmapped'])->toHaveKey('Header')
        ->and($representation->meta['unmapped'])->not->toHaveKey('Header@Caption');
});

test('Indexes больше не числится элементным ключом unmapped', function () {
    [$document, $version] = makeGsfxVersion(buildGsfxZip());

    app(GsfxStrategy::class)->process($document, $version);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::StructuredXml)
        ->first();

    expect($representation->meta['unmapped'])->not->toHaveKey('Indexes')
        ->and($representation->meta['unmapped'])->toHaveKey('Indexes@IndexesMode');
});

test('позиционный K попал в свою позицию со всеми четырьмя родами значений и level', function () {
    [$document, $version] = makeGsfxVersion(buildGsfxZip());

    app(GsfxStrategy::class)->process($document, $version);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::StructuredXml)
        ->first();

    $disk = Storage::disk((string) config('crawler.documents_disk'));
    $decoded = json_decode($disk->get($representation->storage_path), true);

    $positions = $decoded['chapters'][0]['positions'];
    expect($positions[0]['coefficients'])->toHaveCount(1)
        ->and($positions[0]['coefficients'][0])->toMatchArray([
            'caption' => 'Зимнее удорожание',
            'value_oz' => '1,15',
            'value_em' => '1,10',
            'value_zm' => '1,05',
            'value_mt' => '1,02',
            'level' => '1',
        ])
        ->and($positions[1]['coefficients'])->toBe([]);
});

test('документный K попал в GsfxDocument::coefficients и не попал ни в одну позицию', function () {
    [$document, $version] = makeGsfxVersion(buildGsfxZip());

    app(GsfxStrategy::class)->process($document, $version);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::StructuredXml)
        ->first();

    $disk = Storage::disk((string) config('crawler.documents_disk'));
    $decoded = json_decode($disk->get($representation->storage_path), true);

    expect($decoded['coefficients'])->toHaveCount(1)
        ->and($decoded['coefficients'][0])->toMatchArray([
            'caption' => 'К СМР в целом',
            'code' => 'К-1',
            'value_oz' => '1,08',
            'value_em' => '1,03',
            'level' => '2',
            'all_vid_rabs' => 'Yes',
        ])
        ->and($decoded['chapters'][0]['positions'][0]['coefficients'])->toHaveCount(1)
        ->and($decoded['chapters'][0]['positions'][1]['coefficients'])->toBe([]);
});

test('Tzm2022 привязан к своему ресурсу и не появился отдельной записью в resources', function () {
    [$document, $version] = makeGsfxVersion(buildGsfxZip());

    app(GsfxStrategy::class)->process($document, $version);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::StructuredXml)
        ->first();

    $disk = Storage::disk((string) config('crawler.documents_disk'));
    $decoded = json_decode($disk->get($representation->storage_path), true);

    $resources = $decoded['chapters'][0]['positions'][0]['resources'];
    expect($resources)->toHaveCount(2)
        ->and($resources[0]['tzm2022'])->toBeNull()
        ->and($resources[1]['tzm2022'])->toMatchArray([
            'work_class' => '4',
            'quantity' => '1',
            'price' => '826,22',
        ]);
});

test('вид работ несёт четыре норматива, а вид без Nacl даёт null', function () {
    [$document, $version] = makeGsfxVersion(buildGsfxZip());

    app(GsfxStrategy::class)->process($document, $version);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::StructuredXml)
        ->first();

    $disk = Storage::disk((string) config('crawler.documents_disk'));
    $decoded = json_decode($disk->get($representation->storage_path), true);

    $groups = $decoded['work_type_groups'];
    expect($groups[0]['work_types'][0])->toMatchArray([
        'nacl' => '90',
        'plan' => '47',
        'nacl_curr' => '90',
        'plan_curr' => '47',
    ])
        ->and($groups[1]['work_types'][0]['nacl'])->toBeNull()
        ->and($groups[1]['work_types'][0]['plan'])->toBeNull();
});

test('группы видов работ читаются из ОБОИХ блоков Vids_Rab и несут catalog_type', function () {
    [$document, $version] = makeGsfxVersion(buildGsfxZip());

    app(GsfxStrategy::class)->process($document, $version);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::StructuredXml)
        ->first();

    $disk = Storage::disk((string) config('crawler.documents_disk'));
    $decoded = json_decode($disk->get($representation->storage_path), true);

    $groups = $decoded['work_type_groups'];
    expect($groups)->toHaveCount(2)
        ->and($groups[0]['catalog_type'])->toBe('Виды работ 2001г')
        ->and($groups[1]['catalog_type'])->toBe('Виды работ 1984г');
});

test('позиция несёт work_type_id, и его значение совпадает с id вида работ фикстуры', function () {
    [$document, $version] = makeGsfxVersion(buildGsfxZip());

    app(GsfxStrategy::class)->process($document, $version);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::StructuredXml)
        ->first();

    $disk = Storage::disk((string) config('crawler.documents_disk'));
    $decoded = json_decode($disk->get($representation->storage_path), true);

    $positions = $decoded['chapters'][0]['positions'];
    $groups = $decoded['work_type_groups'];
    expect($positions[0]['work_type_id'])->toBe($groups[0]['work_types'][0]['id'])
        ->and($positions[1]['work_type_id'])->toBe($groups[1]['work_types'][0]['id']);
});

test('прочитанные теперь атрибуты исчезли из unmapped, непрочитанный остался', function () {
    [$document, $version] = makeGsfxVersion(buildGsfxZip());

    app(GsfxStrategy::class)->process($document, $version);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::StructuredXml)
        ->first();

    $unmapped = $representation->meta['unmapped'];
    expect($unmapped)->not->toHaveKey('Koefficients')
        ->not->toHaveKey('Tzm2022')
        ->not->toHaveKey('Position@Vr2001')
        ->not->toHaveKey('Vid_Rab@Nacl')
        ->not->toHaveKey('Vid_Rab@Plan')
        ->not->toHaveKey('Vid_Rab@NaclCurr')
        ->not->toHaveKey('Vid_Rab@PlanCurr')
        ->not->toHaveKey('Vids_Rab@Type')
        ->toHaveKey('Vid_Rab@NaclMask');
});

test('повторный разбор GsfxReader идемпотентен для index_code и каталога индексов', function () {
    $properties = file_get_contents(base_path('tests/Fixtures/gsfx/Properties.txt'));
    $data = file_get_contents(base_path('tests/Fixtures/gsfx/Data.xml'));

    $reader = app(GsfxReader::class);

    $first = $reader->parse($properties, $data)->toArray();
    $second = $reader->parse($properties, $data)->toArray();

    expect($second)->toBe($first);
});

test('битый контейнер — отсутствующий Data.xml → unprocessable(unreadable_format)', function () {
    $zipPath = tempnam(sys_get_temp_dir(), 'gsfx_test_').'.gsfx';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFile(base_path('tests/Fixtures/gsfx/Properties.txt'), 'Properties.txt');
    $zip->close();

    [$document, $version] = makeGsfxVersion($zipPath);

    $result = app(GsfxStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::Unprocessable)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($document->last_error)->toContain('unreadable_format');
});

test('XXE в Data.xml отклоняется до парсинга — unprocessable(unreadable_format)', function () {
    $maliciousXml = <<<'XML'
    <?xml version="1.0" encoding="windows-1251"?>
    <!DOCTYPE Document [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
    <Document>&xxe;</Document>
    XML;

    [$document, $version] = makeGsfxVersion(buildGsfxZip(['Data.xml' => $maliciousXml]));

    $result = app(GsfxStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::Unprocessable)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($document->last_error)->toContain('unreadable_format');
});

test('большой decoded Data.xml отклоняется до isolated parser по отдельному core limit', function (): void {
    config([
        'ai.prepare.gsfx_max_core_xml_mb' => 1,
        'ai.prepare.archive.max_entry_mb' => 8,
        'ai.prepare.archive.max_total_mb' => 16,
        'ai.prepare.archive.max_ratio' => 10_000,
    ]);
    [$document, $version] = makeGsfxVersion(buildGsfxZip([
        'Data.xml' => '<Document>'.str_repeat(' ', 2 * 1024 * 1024).'</Document>',
    ]));

    $result = app(GsfxStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::Unprocessable)
        ->and($document->fresh()->unprocessable_reason)->toBe(UnprocessableReason::ZipBomb);
});

test('вложение стало дочерним документом', function () {
    Queue::fake();
    [$document, $version] = makeGsfxVersion(buildGsfxZip([
        'Attachments/photo.png' => 'fake-png-bytes',
    ]));

    app(GsfxStrategy::class)->process($document, $version);

    $child = Document::query()
        ->where('external_id', $version->id.':Attachments/photo.png')
        ->first();

    expect($child)->not->toBeNull();

    $childVersion = DocumentVersion::query()->where('document_id', $child->id)->first();
    expect($childVersion->parent_version_id)->toBe($version->id)
        ->and($childVersion->archive_entry_path)->toBe('Attachments/photo.png')
        ->and($childVersion->filename)->toBe('photo.png');
    Queue::assertNothingPushed();
});

test('вредоносное имя entry (zip-slip) отклоняет весь GSFX без child effects', function () {
    Queue::fake();
    [$document, $version] = makeGsfxVersion(buildGsfxZip([
        'Attachments/../../../evil.txt' => 'payload',
    ]));

    $result = app(GsfxStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::Unprocessable)
        ->and($document->refresh()->unprocessable_reason)->toBe(UnprocessableReason::ZipBomb)
        ->and(DocumentVersion::query()->where('parent_version_id', $version->id)->count())->toBe(0)
        ->and(ProcurementEvent::query()
            ->where('procurement_id', $document->procurement_id)
            ->where('event_type', ProcurementEventType::DocumentAdded)
            ->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('повторный прогон идемпотентен — одно представление каждого kind', function () {
    [$document, $version] = makeGsfxVersion(buildGsfxZip());

    app(GsfxStrategy::class)->process($document, $version);
    app(GsfxStrategy::class)->process($document, $version);

    expect(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(2)
        ->and(DocumentRepresentation::query()->where('document_version_id', $version->id)->where('kind', RepresentationKind::StructuredXml)->count())->toBe(1)
        ->and(DocumentRepresentation::query()->where('document_version_id', $version->id)->where('kind', RepresentationKind::Text)->count())->toBe(1);
});

test('registry и inspector подтверждают typed GSFX contract', function () {
    Storage::fake('s3');
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $path = 'documents/contract.gsfx';
    Storage::disk('s3')->put($path, file_get_contents(buildGsfxZip()));
    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'disk' => 's3',
        'extension' => 'gsfx',
        'filename' => 'contract.gsfx',
        'storage_path' => $path,
    ]);

    expect(app(DocumentFormatRegistry::class)->forExtension('gsfx')['strategy'])->toBe(GsfxStrategy::class)
        ->and(app(DocumentFormatInspector::class)->inspect($version)->strategy)->toBe(GsfxStrategy::class);
});

test('inspector отклоняет gsfx-расширение без ZIP-сигнатуры', function () {
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $disk = Storage::fake((string) config('crawler.documents_disk'));
    $disk->put('documents/not-a-zip.gsfx', 'not a zip at all');

    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'extension' => 'gsfx',
        'storage_path' => 'documents/not-a-zip.gsfx',
    ]);

    expect(app(DocumentFormatInspector::class)->inspect($version)->reason)->toBe(UnprocessableReason::FormatMismatch);
});
