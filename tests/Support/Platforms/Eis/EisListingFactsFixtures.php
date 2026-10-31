<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\Eis;

use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Platforms\Eis\EisListingFilter;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Acquisition\ListingPage;
use App\Dto\Procurement\Acquisition\ListingRequest;
use App\Dto\Procurement\Network\DecodedTextBody;
use App\Dto\Procurement\Network\ResponseEnvelope;
use App\Services\Platforms\Eis\EisListingSource;
use Tests\Support\Platforms\Contract\RecordingOutbound;
use Tests\Support\ProcessCore\LedgerFixtures;

final class EisListingFactsFixtures
{
    public static function row(string $id, array $overrides = []): array
    {
        return array_replace([
            'Реестровый номер закупки' => $id, 'Наименование закупки' => 'Поставка оргтехники',
            'Этап закупки' => 'Подача заявок', 'Дата размещения' => '01.03.2026',
            'Дата обновления' => '02.03.2026 10:15:00', 'Начальная (максимальная) цена контракта' => '1250000,50',
            'Валюта' => 'RUB',
        ], $overrides);
    }

    public static function body(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        $header = array_keys($rows[0] ?? self::row('1'));
        fputcsv($stream, $header, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, array_values($row), ';', '"', '');
        }
        rewind($stream);
        $body = stream_get_contents($stream);
        fclose($stream);

        return $body;
    }

    public static function parse(string $body, int $status = 200, string $type = 'text/csv', int $blockSize = 500): ListingPage
    {
        config(['platforms.eis.block_size' => $blockSize]);
        $source = new EisListingSource(new EisPolicy(config()), new RecordingOutbound, LedgerFixtures::uuid());
        $filter = new EisListingFilter(['123']);
        $cursor = new EisListingCursor('2026-03-01', 'publish', 0, 100000000000, $filter->fingerprint(), blockSize: $blockSize);

        return $source->parse(new ListingRequest('fz44', $filter, $cursor->envelope()),
            new ResponseEnvelope($status, ['content-type' => [$type]], new DecodedTextBody($body, 'UTF-8'), strlen($body), strlen($body)));
    }
}
