<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Gge;

use Generator;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;

final class GgeXmlParser
{
    private const int XML_OPTIONS = LIBXML_NONET | LIBXML_NSCLEAN | LIBXML_COMPACT;

    /** @return Generator<int, array<string, mixed>> */
    public function streamItems(string $xmlFilePath): Generator
    {
        foreach ($this->streamNodes($xmlFilePath) as $node) {
            if ($node['kind'] === 'item' || $node['kind'] === 'work') {
                yield $node;
            }
        }
    }

    /** @return array{schema_family: string, sections: list<array{num: ?string, name: ?string}>, items: list<array<string, mixed>>} */
    public function parse(string $xmlFilePath): array
    {
        $result = ['schema_family' => 'gge_unknown', 'sections' => [], 'items' => []];

        foreach ($this->streamNodes($xmlFilePath) as $node) {
            if ($node['kind'] === 'schema') {
                $family = $node['schema_family'] ?? null;
                $result['schema_family'] = is_string($family) ? $family : 'gge_unknown';
            } elseif ($node['kind'] === 'section') {
                $num = $node['num'] ?? null;
                $name = $node['name'] ?? null;
                $result['sections'][] = [
                    'num' => is_string($num) ? $num : null,
                    'name' => is_string($name) ? $name : null,
                ];
            } elseif ($node['kind'] === 'section_update') {
                $last = count($result['sections']) - 1;
                if ($last >= 0) {
                    $num = $node['num'] ?? null;
                    $name = $node['name'] ?? null;
                    $result['sections'][$last] = [
                        'num' => is_string($num) ? $num : null,
                        'name' => is_string($name) ? $name : null,
                    ];
                }
            } else {
                $result['items'][] = $node;
            }
        }

        return $result;
    }

    /** @return Generator<int, array<string, mixed>> */
    private function streamNodes(string $xmlFilePath): Generator
    {
        $realPath = $this->localFile($xmlFilePath);
        $this->assertSafeProlog($realPath);

        $reader = new XMLReader;
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $reader->open($realPath, null, self::XML_OPTIONS)) {
                throw new RuntimeException('Cannot open GGE XML.');
            }

            $section = ['num' => null, 'name' => null];
            $sectionDepth = -1;
            $hasNode = $reader->read();
            while ($hasNode) {
                if ($reader->nodeType === XMLReader::DOC_TYPE) {
                    throw new RuntimeException('DOCTYPE is forbidden in GGE XML.');
                }
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    $hasNode = $reader->read();

                    continue;
                }

                $name = $reader->localName;
                if ($name === 'Construction') {
                    $schema = $reader->getAttributeNs('noNamespaceSchemaLocation', 'http://www.w3.org/2001/XMLSchema-instance')
                        ?? $reader->getAttribute('xsi:noNamespaceSchemaLocation');
                    yield ['kind' => 'schema', 'schema_family' => self::schemaFamily($schema)];
                } elseif ($name === 'Section') {
                    $section = ['num' => $reader->getAttribute('Num'), 'name' => $reader->getAttribute('Name')];
                    $sectionDepth = $reader->depth;
                    yield ['kind' => 'section', ...$section];
                } elseif (($name === 'Num' || $name === 'Name') && $reader->depth === $sectionDepth + 1) {
                    $key = strtolower($name);
                    $section[$key] = trim($reader->readString());
                    yield ['kind' => 'section_update', ...$section];
                } elseif ($name === 'Item' || $name === 'Work') {
                    $outer = $reader->readOuterXml();
                    $element = simplexml_load_string($outer, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NSCLEAN);
                    if ($element === false) {
                        throw new RuntimeException('Invalid GGE item XML.');
                    }
                    yield $name === 'Item' ? $this->item($element, $section) : $this->work($element, $section);
                    $hasNode = $reader->next();

                    continue;
                }

                $hasNode = $reader->read();
            }

            if (libxml_get_errors() !== []) {
                throw new RuntimeException('Invalid GGE XML.');
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function localFile(string $path): string
    {
        if (str_contains($path, '://') || ($realPath = realpath($path)) === false || ! is_file($realPath)) {
            throw new RuntimeException('GGE XML must be a local file.');
        }

        return $realPath;
    }

    private function assertSafeProlog(string $path): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot read GGE XML.');
        }
        try {
            $prefix = fread($handle, 512);
        } finally {
            fclose($handle);
        }
        if ($prefix === false || str_contains($prefix, "\x1B") || stripos($prefix, '+ADw-') !== false) {
            throw new RuntimeException('Unsafe GGE XML encoding.');
        }

        $encoding = 'UTF-8';
        if (str_starts_with($prefix, "\xFF\xFE")) {
            $encoding = 'UTF-16LE';
        } elseif (str_starts_with($prefix, "\xFE\xFF")) {
            $encoding = 'UTF-16BE';
        } elseif (str_starts_with($prefix, "<\x00?\x00")) {
            $encoding = 'UTF-16LE';
        } elseif (str_starts_with($prefix, "\x00<\x00?")) {
            $encoding = 'UTF-16BE';
        }
        if ($encoding !== 'UTF-8') {
            $prefix = mb_convert_encoding($prefix, 'UTF-8', $encoding);
        }
        if (str_contains($prefix, "\x1B") || stripos($prefix, '+ADw-') !== false) {
            throw new RuntimeException('Unsafe GGE XML encoding.');
        }
        if (preg_match('/^(?:\xEF\xBB\xBF)?\s*<\?xml\b[^>]*\bencoding\s*=\s*(["\'])([^"\']+)\1/is', $prefix, $match) === 1
            && ! in_array(strtoupper($match[2]), ['UTF-8', 'UTF-16', 'UTF-16LE', 'UTF-16BE', 'WINDOWS-1251', 'CP1251'], true)) {
            throw new RuntimeException('Unsupported GGE XML encoding.');
        }
    }

    public static function schemaFamily(?string $schema): string
    {
        if ($schema === null) {
            return 'gge_unknown';
        }

        return match (true) {
            stripos($schema, 'LocalEstimateBaseIndexMethod') !== false => 'gge_bim',
            stripos($schema, 'LocalEstimateResourceIndexMethod') !== false => 'gge_rim',
            stripos($schema, 'QuantityTakeoff') !== false => 'gge_vor',
            default => 'gge_unknown',
        };
    }

    /** @param array{num: ?string, name: ?string} $section
     * @return array<string, mixed>
     */
    private function item(SimpleXMLElement $node, array $section): array
    {
        return [
            'kind' => 'item', 'section_num' => $section['num'], 'section_name' => $section['name'],
            'num' => $this->field($node, 'Cost/Num') ?? $this->field($node, 'Num'),
            'prefix' => $this->field($node, 'Prefix'), 'code' => $this->field($node, 'Code'),
            'name' => $this->field($node, 'Name'), 'unit' => $this->field($node, 'Unit'),
            'quantity' => $this->field($node, 'Quantity'), 'quantity_formula' => $this->field($node, 'QuantityFormula'),
            'quantity_total' => $this->field($node, 'QuantityTotal'),
            'price_cur' => $this->field($node, 'Totals/PriceCur'), 'price_base' => $this->field($node, 'Totals/PriceBase'),
            'overheads_price_cur' => $this->field($node, 'Overheads/PriceCur'),
            'profits_price_cur' => $this->field($node, 'Profits/PriceCur'),
            'resources' => $this->children($node, 'Resources/Resource'),
        ];
    }

    /** @param array{num: ?string, name: ?string} $section
     * @return array<string, mixed>
     */
    private function work(SimpleXMLElement $node, array $section): array
    {
        return [
            'kind' => 'work', 'section_num' => $section['num'], 'section_name' => $section['name'],
            'num' => $this->field($node, 'Num'), 'work_type' => $this->field($node, 'Type'),
            'name' => $this->field($node, 'Name'), 'unit' => $this->field($node, 'Unit'),
            'quantity' => $this->field($node, 'Quantity'), 'quantity_formula' => $this->field($node, 'QuantityFormula'),
            'links' => $this->children($node, 'Links/Link'), 'resources' => $this->children($node, 'Resources/Resource'),
        ];
    }

    private function field(SimpleXMLElement $node, string $path): ?string
    {
        foreach (explode('/', $path) as $part) {
            if (isset($node[$part])) {
                $attribute = $node[$part];

                return (string) $attribute;
            }
            if (! isset($node->{$part})) {
                return null;
            }
            $child = $node->{$part};
            if (! $child instanceof SimpleXMLElement) {
                return null;
            }
            $node = $child;
        }

        if (isset($node['Value'])) {
            $value = $node['Value'];

            return (string) $value;
        }

        return trim((string) $node);
    }

    /** @return list<array<string, ?string>> */
    private function children(SimpleXMLElement $node, string $path): array
    {
        $parts = explode('/', $path);
        $leaf = array_pop($parts);
        foreach ($parts as $part) {
            if (! isset($node->{$part})) {
                return [];
            }
            $node = $node->{$part};
        }
        $result = [];
        $children = $node->{$leaf} ?? null;
        if (! $children instanceof SimpleXMLElement) {
            return [];
        }
        foreach ($children as $child) {
            $result[] = [
                'num' => $this->field($child, 'Num'), 'type' => $this->field($child, 'Type'),
                'code' => $this->field($child, 'Code'), 'name' => $this->field($child, 'Name'),
                'unit' => $this->field($child, 'Unit'), 'quantity' => $this->field($child, 'Quantity'),
                'price_cur' => $this->field($child, 'PriceCur'), 'price_base' => $this->field($child, 'PriceBase'),
            ];
        }

        return $result;
    }
}
