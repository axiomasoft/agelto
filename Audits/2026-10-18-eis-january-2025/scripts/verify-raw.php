<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::statement('SET default_transaction_read_only = on');
$runId = $argv[1] ?? '';
if (! ctype_digit($runId) || (int) $runId < 1) {
    fwrite(STDERR, 'Usage: php verify-raw.php RUN_ID'.PHP_EOL);
    exit(1);
}

$result = ['utc' => gmdate(DATE_ATOM), 'run_id' => (int) $runId, 'fetches' => [], 'missing_registry_numbers' => []];
$run = DB::table('prc_crawler_runs')->where('id', $runId)->first(['status', 'crawler_id']);
if ($run === null) {
    fwrite(STDERR, 'Run does not exist.'.PHP_EOL);
    exit(1);
}
$result['run_status'] = $run->status;
$registryId = DB::table('prc_crawlers')->where('id', $run->crawler_id)->value('registry_id');
$result['scope'] = 'Consistency of saved raw responses only; does not establish source completeness.';
$allNumbers = [];
$disk = Storage::disk(config('crawler.raw_disk'));
foreach (DB::table('prc_fetches')->where('crawler_run_id', $runId)->where('stage', 'listing')->orderBy('id')->get() as $fetch) {
    $stream = fopen('php://temp', 'r+');
    $body = $disk->get($fetch->artifact_ref);
    fwrite($stream, $body);
    rewind($stream);
    $header = fgetcsv($stream, separator: ';', enclosure: '"', escape: '');
    $requiredHeaders = ['Реестровый номер закупки', 'Дата размещения', 'Дата обновления'];
    $isCsv = is_array($header) && array_diff($requiredHeaders, $header) === [];
    $validRows = 0;
    $invalidRows = 0;
    $multilineRows = 0;
    while (($values = fgetcsv($stream, separator: ';', enclosure: '"', escape: '')) !== false) {
        if ($values === [null]) {
            continue;
        }
        if (! $isCsv || count($values) !== count($header)) {
            $invalidRows++;
            continue;
        }
        $validRows++;
        $multilineRows += count(array_filter($values, static fn ($value): bool => str_contains((string) $value, "\n") || str_contains((string) $value, "\r"))) > 0 ? 1 : 0;
        $row = array_combine($header, $values);
        if (count(array_filter($requiredHeaders, static fn ($key): bool => trim((string) $row[$key]) === '')) > 0) {
            $invalidRows++;
        }
        $number = preg_replace('/\D/u', '', $row['Реестровый номер закупки']);
        if (is_string($number) && $number !== '') {
            $allNumbers[$number] = true;
        }
    }
    fclose($stream);
    $params = json_decode($fetch->request_params, true, flags: JSON_THROW_ON_ERROR);
    $result['fetches'][] = [
        'id' => $fetch->id, 'day' => $params['publishDateFrom'] ?? null,
        'from' => $params['from'] ?? null, 'price_from' => $params['priceFromGeneral'] ?? null,
        'price_to' => $params['priceToGeneral'] ?? null, 'http_status' => $fetch->http_status,
        'bytes' => strlen($body), 'sha256' => hash('sha256', $body), 'csv_header_valid' => $isCsv,
        'stream_rows' => $validRows, 'recorded_rows' => $fetch->rows_total,
        'invalid_rows' => $invalidRows, 'multiline_rows' => $multilineRows,
    ];
}

$known = DB::table('prc_procurements')->where('registry_id', $registryId)->whereIn('registry_number', array_keys($allNumbers))->pluck('registry_number')->all();
$result['days_covered'] = array_values(array_unique(array_column($result['fetches'], 'day')));
$result['unique_raw_registry_numbers'] = count($allNumbers);
$result['missing_registry_numbers'] = array_values(array_diff(array_keys($allNumbers), $known));
$result['mismatched_fetches'] = count(array_filter($result['fetches'], static fn ($row): bool => ! $row['csv_header_valid'] || $row['stream_rows'] !== $row['recorded_rows'] || $row['invalid_rows'] > 0 || $row['http_status'] !== 200));
$result['outcome'] = $result['fetches'] !== [] && $result['mismatched_fetches'] === 0 && $result['missing_registry_numbers'] === [] ? 'raw_consistent' : 'data_loss_or_invalid_response';
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
exit($result['outcome'] === 'raw_consistent' ? 0 : 2);
