<?php

declare(strict_types=1);

// Local fixture server only. No application bootstrap, database access or outbound requests.
$mode = $_GET['mode'] ?? 'csv';
if ($mode === 'slow') {
    header('Content-Length: 100');
    echo 'x';
    flush();
    sleep(2);
    echo str_repeat('x', 99);

    return;
}
if ($mode === 'gzip') {
    header('Content-Encoding: gzip');
    echo gzencode(str_repeat('x', 16 * 1024 * 1024));

    return;
}
if ($mode === 'redirect') {
    header('Location: /?mode=redirect', true, 302);

    return;
}
$attempt = (int) ($_GET['attempt'] ?? 3);
if ($attempt < 3) {
    http_response_code($attempt === 1 ? 503 : 429);
    echo 'retry';

    return;
}
$worker = (int) ($_GET['worker'] ?? 1);
$stream = fopen('php://temp', 'w+');
fputcsv($stream, ['Реестровый номер закупки', 'Дата размещения', 'Дата обновления', 'Наименование закупки'], ';', '"', '');
for ($row = 1; $row <= 500; $row++) {
    fputcsv($stream, [sprintf('benchmark-%02d-%04d', $worker, $row), '01.10.2026', '02.10.2026 12:00:00', str_repeat('Тест закупки ', 20)], ';', '"', '');
}
rewind($stream);
header('Content-Type: text/csv');
echo mb_convert_encoding(stream_get_contents($stream), 'Windows-1251', 'UTF-8');
fclose($stream);
