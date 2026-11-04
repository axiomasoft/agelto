<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Tests\Support\ProcessCore\AcquisitionWriteRules;

test('known canonical creators and extension writes have one owner', function (): void {
    $violations = [];
    foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
        array_push($violations, ...AcquisitionWriteRules::violations('app/'.$file->getRelativePathname(), $file->getContents()));
    }
    expect($violations)->toBe([]);
})->group('platform-extension-ownership');

test('writer guard rejects forbidden model and table-qualified persistence', function (string $source): void {
    $fixture = tempnam(sys_get_temp_dir(), 'acquisition-writer-');
    try {
        file_put_contents($fixture, '<?php '.$source);
        expect(AcquisitionWriteRules::violations('app/Services/UnexpectedWriter.php', file_get_contents($fixture)))->toHaveCount(1);
    } finally {
        unlink($fixture);
    }
})->with([
    'extension model' => 'EisDetails::query()->upsert($rows, ["procurement_id"]);',
    'extension table' => 'DB::table("prc_eis_details")->where("procurement_id", 1)->update($values);',
    'direct creator' => 'Procurement::create($values);',
    'canonical model' => 'Procurement::query()->create($values);',
    'canonical table' => 'DB::table("prc_procurements")->insert($values);',
    'raw procurement attributes' => 'Procurement::query()->where("attributes->ikz", $value)->first();',
])->group('platform-extension-ownership');

test('writer guard permits extension reads', function (): void {
    expect(AcquisitionWriteRules::violations('app/Filament/Platforms/Eis/EisUiExtension.php', 'EisDetails::query()->find($id);'))->toBe([]);
    expect(AcquisitionWriteRules::violations('app/Services/Other.php', 'DB::table("prc_ai_analyses")->whereJsonContains("attributes->tags", $value);'))->toBe([]);
})->group('platform-extension-ownership');
