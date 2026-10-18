<?php

declare(strict_types=1);

// Synthetic fixtures only: no application bootstrap, network, database, or credentials.
require dirname(__DIR__, 3).'/vendor/autoload.php';

use App\Models\Procurement\Crawler\Crawler;
use App\Services\Procurement\Eis\CsvSourceMethod;
use App\Services\Procurement\Eis\EisCsvRowMapper;

$parser = new ReflectionMethod(CsvSourceMethod::class, 'parseCsv');
$source = (new ReflectionClass(CsvSourceMethod::class))->newInstanceWithoutConstructor();
$header = ['Реестровый номер закупки', 'Наименование закупки', 'Дата размещения', 'Дата обновления'];
$stream = fopen('php://memory', 'r+');
fputcsv($stream, $header, separator: ';', enclosure: '"', escape: '');

for ($number = 1; $number <= 500; $number++) {
    fputcsv($stream, [
        '№'.str_pad((string) $number, 19, '0', STR_PAD_LEFT),
        $number === 250 ? "Ремонт\nпомещения" : 'Ремонт',
        '10.01.2025',
        '11.01.2025',
    ], separator: ';', enclosure: '"', escape: '');
}

rewind($stream);
$csv = stream_get_contents($stream);
rewind($stream);
fgetcsv($stream, separator: ';', enclosure: '"', escape: '');
$referenceCount = 0;

while (fgetcsv($stream, separator: ';', enclosure: '"', escape: '') !== false) {
    $referenceCount++;
}

fclose($stream);
$actualRows = $parser->invoke($source, $csv);
$actualCount = count($actualRows);
$actualTitle = $actualRows[249]['Наименование закупки'] ?? null;
$html = '<html><body>Access denied</body></html>';
$mapper = new EisCsvRowMapper;
$crawler = (new ReflectionClass(Crawler::class))->newInstanceWithoutConstructor();
$mapperResults = [];

foreach ([
    'missing_number' => ['Дата размещения' => '10.01.2025', 'Дата обновления' => '11.01.2025'],
    'missing_published_date' => ['Реестровый номер закупки' => '№0123456789025000001', 'Дата обновления' => '11.01.2025'],
    'missing_updated_date' => ['Реестровый номер закупки' => '№0123456789025000001', 'Дата размещения' => '10.01.2025'],
] as $name => $row) {
    try {
        $mapper->map($row, $crawler);
        $mapperResults[$name] = ['throws' => false];
    } catch (UnexpectedValueException) {
        $mapperResults[$name] = ['throws' => true];
    }
}

$rejected = [];
foreach (['html_response' => $html, 'empty_response' => ''] as $name => $body) {
    try {
        $parser->invoke($source, $body);
        $rejected[$name] = ['throws' => false];
    } catch (UnexpectedValueException) {
        $rejected[$name] = ['throws' => true];
    }
}
$passed = $actualCount === 500 && $referenceCount === 500
    && $actualTitle === "Ремонт\nпомещения"
    && ! in_array(false, array_column($mapperResults, 'throws'), true)
    && ! in_array(false, array_column($rejected, 'throws'), true);

$evidence = [
    'checked_at_utc' => gmdate(DATE_ATOM),
    'php_version' => PHP_VERSION,
    'source_sha256' => hash_file('sha256', dirname(__DIR__, 3).'/app/Services/Procurement/Eis/CsvSourceMethod.php'),
    'parser_sha256' => hash_file('sha256', dirname(__DIR__, 3).'/app/Services/Procurement/Eis/EisCsvParser.php'),
    'method' => 'Reflection invokes current private parseCsv; reference fgetcsv reads full stream; constructorless source/crawler; no application bootstrap',
    'multiline_csv' => [
        'headers' => $header,
        'generated_data_record_count' => 500,
        'record_250_title' => "Ремонт\nпомещения",
        'actual_record_250_title' => $actualTitle,
        'reference_fgetcsv_record_count' => $referenceCount,
        'actual_parseCsv_record_count' => $actualCount,
        'scanner_rowsTotal_less_than_500' => $actualCount < 500,
        'fixture_sha256' => hash('sha256', $csv),
    ],
    ...$rejected,
    'mapper_missing_required_fields' => $mapperResults,
    'outcome' => $passed ? 'preserved_and_rejected' : 'failed',
    'limits' => 'Synthetic local parser/mapper proof; HTTP transport and live EIS behavior are not tested here.',
];

echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
exit($passed ? 0 : 1);
