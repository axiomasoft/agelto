<?php

declare(strict_types=1);

use App\Services\Procurement\Prepare\Gge\GgeXmlParser;
use App\Services\Procurement\Prepare\NativeEstimateFormatDetector;

function nativeEstimateFile(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'native_estimate_');
    if ($path === false || file_put_contents($path, $bytes) === false) {
        throw new RuntimeException('Cannot create native estimate fixture.');
    }

    return $path;
}

test('потоково извлекает секции позиции и работы из выгрузки Минстроя', function (): void {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Construction xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="LocalEstimateBaseIndexMethod.xsd">
  <Sections>
    <Section Num="1" Name="Земляные работы">
      <Item Prefix="ФЕР" Code="01-01" Name="Разработка грунта" Unit="1000 м2" Quantity="0.00355" QuantityFormula="355/100000" QuantityTotal="0.00355">
        <Cost Num="7"/><Totals><PriceCur>7873.07</PriceCur><PriceBase Value="6000.00"/></Totals>
        <Overheads><PriceCur>200.00</PriceCur></Overheads><Profits><PriceCur>100.00</PriceCur></Profits>
        <Resources><Resource Code="М-1" Name="Материал" Unit="т" Quantity="2" PriceCur="120.00"/></Resources>
      </Item>
      <Work Num="8" Type="volume" Name="Разработка" Unit="м3" Quantity="3" QuantityFormula="1+2">
        <Links><Link Num="7"/></Links><Resources><Resource Code="Т-1" Name="Труд" Quantity="4"/></Resources>
      </Work>
    </Section>
    <Section Num="2" Name="Следующая"><Item Num="9" Name="Позиция"/></Section>
  </Sections>
</Construction>
XML;
    $path = nativeEstimateFile($xml);
    try {
        $parser = new GgeXmlParser;
        $items = iterator_to_array($parser->streamItems($path));
        $parsed = $parser->parse($path);

        expect($items)->toHaveCount(3)
            ->and($parsed['schema_family'])->toBe('gge_bim')
            ->and($parsed['sections'])->toBe([
                ['num' => '1', 'name' => 'Земляные работы'],
                ['num' => '2', 'name' => 'Следующая'],
            ])
            ->and($parsed['items'])->toBe($items)
            ->and($items[0]['num'])->toBe('7')
            ->and($items[0]['price_cur'])->toBe('7873.07')
            ->and($items[0]['price_base'])->toBe('6000.00')
            ->and($items[0]['overheads_price_cur'])->toBe('200.00')
            ->and($items[0]['profits_price_cur'])->toBe('100.00')
            ->and($items[0]['resources'][0]['code'])->toBe('М-1')
            ->and($items[1]['kind'])->toBe('work')
            ->and($items[1]['links'][0]['num'])->toBe('7')
            ->and($items[2]['section_num'])->toBe('2');
    } finally {
        unlink($path);
    }
});

test('распознаёт семейства БИМ РИМ и ВОР по сигнатуре схемы', function (string $schema, string $family): void {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<Construction xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="'.$schema.'">'
        .'<Section Num="1" Name="Раздел"><Work Num="1" Name="Работа"/></Section></Construction>';
    $path = nativeEstimateFile($xml);
    try {
        expect((new NativeEstimateFormatDetector)->detect($path))->toBe($family)
            ->and((new GgeXmlParser)->parse($path)['schema_family'])->toBe($family);
    } finally {
        unlink($path);
    }
})->with([
    ['LocalEstimateBaseIndexMethod.xsd', 'gge_bim'],
    ['LocalEstimateResourceIndexMethod.xsd', 'gge_rim'],
    ['QuantityTakeoff.xsd', 'gge_vor'],
]);

test('распознаёт самостоятельный XML ГРАНД-Сметы по структуре или версии', function (string $xml): void {
    $path = nativeEstimateFile($xml);
    try {
        expect((new NativeEstimateFormatDetector)->detect($path))->toBe('grand_smeta_xml');
    } finally {
        unlink($path);
    }
})->with([
    '<Document><Chapters><Chapter Caption="Глава"/></Chapters></Document>',
    '<Document ProgramVersion="GRAND 2026"/>',
]);

test('распознаёт сигнатуру АРПС в трёх кодировках', function (string $encoding): void {
    $header = '1#АРПС 1.10#';
    $bytes = $encoding === 'UTF-8' ? $header : mb_convert_encoding($header, $encoding, 'UTF-8');
    $path = nativeEstimateFile($bytes);
    try {
        expect((new NativeEstimateFormatDetector)->detect($path))->toBe('arps_1_10');
    } finally {
        unlink($path);
    }
})->with(['UTF-8', 'Windows-1251', 'CP866']);

test('отклоняет DOCTYPE и небезопасные кодировки до разбора XML', function (string $xml): void {
    $path = nativeEstimateFile($xml);
    try {
        expect(fn () => (new GgeXmlParser)->parse($path))->toThrow(RuntimeException::class);
    } finally {
        unlink($path);
    }
})->with([
    '<?xml version="1.0"?><!DOCTYPE Construction [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><Construction><Item Name="&xxe;"/></Construction>',
    '<?xml version="1.0" encoding="UTF-7"?><Construction/>',
    '<?xml version="1.0" encoding="UTF-8"?><Construction>+ADw-script</Construction>',
    "<?xml version=\"1.0\"?><Construction>\x1B</Construction>",
]);

test('не принимает URI-пути и неизвестный формат', function (): void {
    $path = nativeEstimateFile('<Unknown/>');
    try {
        expect((new NativeEstimateFormatDetector)->detect($path))->toBeNull()
            ->and(fn () => (new NativeEstimateFormatDetector)->detect('phar://'.$path))->toThrow(RuntimeException::class)
            ->and(fn () => iterator_to_array((new GgeXmlParser)->streamItems('phar://'.$path)))->toThrow(RuntimeException::class);
    } finally {
        unlink($path);
    }
});

test('читает номер и имя секции из дочерних элементов без загрузки секции целиком', function (): void {
    $xml = '<Construction><Section><Num>3</Num><Name>Монтаж</Name><Item><Cost><Num>5</Num></Cost><Name>Работа</Name></Item></Section></Construction>';
    $path = nativeEstimateFile($xml);
    try {
        $parsed = (new GgeXmlParser)->parse($path);
        expect($parsed['sections'])->toBe([['num' => '3', 'name' => 'Монтаж']])
            ->and($parsed['items'][0]['section_num'])->toBe('3')
            ->and($parsed['items'][0]['section_name'])->toBe('Монтаж')
            ->and($parsed['items'][0]['num'])->toBe('5');
    } finally {
        unlink($path);
    }
});

test('читает разрешённые кодировки XML с кириллицей', function (string $declaration, string $encoding, string $bom): void {
    $xml = '<?xml version="1.0" encoding="'.$declaration.'"?>'
        .'<Construction xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="QuantityTakeoff.xsd">'
        .'<Section Num="1" Name="Секция"><Work Num="2" Name="Монтаж"/></Section></Construction>';
    $path = nativeEstimateFile($bom.mb_convert_encoding($xml, $encoding, 'UTF-8'));
    try {
        expect((new NativeEstimateFormatDetector)->detect($path))->toBe('gge_vor')
            ->and((new GgeXmlParser)->parse($path)['items'][0]['name'])->toBe('Монтаж');
    } finally {
        unlink($path);
    }
})->with([
    'UTF-16LE с BOM' => ['UTF-16LE', 'UTF-16LE', "\xFF\xFE"],
    'Windows-1251' => ['windows-1251', 'Windows-1251', ''],
]);

test('не принимает вложенные и закомментированные сигнатуры за корень документа', function (string $xml): void {
    $path = nativeEstimateFile($xml);
    try {
        expect((new NativeEstimateFormatDetector)->detect($path))->toBeNull();
    } finally {
        unlink($path);
    }
})->with([
    '<Other><Construction xsi:noNamespaceSchemaLocation="LocalEstimateBaseIndexMethod.xsd"/></Other>',
    '<!-- <Document ProgramVersion="GRAND"/> --><Other/>',
    '<Document><!-- <Chapters/> --></Document>',
]);
