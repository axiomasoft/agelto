<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Gsfx;

use App\Dto\Procurement\Prepare\Gsfx\GsfxChapter;
use App\Dto\Procurement\Prepare\Gsfx\GsfxCoefficient;
use App\Dto\Procurement\Prepare\Gsfx\GsfxIndex;
use App\Dto\Procurement\Prepare\Gsfx\GsfxIndexCatalog;
use App\Dto\Procurement\Prepare\Gsfx\GsfxPosition;
use App\Dto\Procurement\Prepare\Gsfx\GsfxResource;
use App\Dto\Procurement\Prepare\Gsfx\GsfxTzm2022;
use SimpleXMLElement;

final readonly class GsfxXmlParser
{
    /**
     * @param  array<string, int>  $unmapped
     * @return list<GsfxChapter>
     */
    public function parseChapters(SimpleXMLElement $xml, array &$unmapped): array
    {
        $chapters = [];

        if (isset($xml->Chapters)) {
            $this->tallyUnreadAttributes($xml->Chapters, 'Chapters', [], $unmapped);
        }

        foreach ($xml->Chapters->Chapter ?? [] as $chapterNode) {
            $this->tallyUnreadAttributes($chapterNode, 'Chapter', ['Caption', 'SysID'], $unmapped);

            $positions = [];

            foreach ($chapterNode->children() as $child) {
                $name = $child->getName();

                if ($name === 'Position') {
                    $positions[] = $this->parsePosition($child, $unmapped);

                    continue;
                }

                $this->tally($unmapped, $name);
            }

            $chapters[] = new GsfxChapter(
                caption: (string) $chapterNode['Caption'],
                sys_id: $this->attr($chapterNode, 'SysID'),
                positions: $positions,
            );
        }

        return $chapters;
    }

    /**
     * @param  array<string, int>  $unmapped
     * @return list<GsfxCoefficient>
     */
    public function parseCoefficients(SimpleXMLElement $koefficientsNode, array &$unmapped): array
    {
        $this->tallyUnreadAttributes($koefficientsNode, 'Koefficients', [], $unmapped);

        $coefficients = [];

        foreach ($koefficientsNode->K ?? [] as $kNode) {
            $this->tallyUnreadAttributes(
                $kNode,
                'K',
                ['Caption', 'Code', 'Options', 'Value_OZ', 'Value_EM', 'Value_ZM', 'Value_MT', 'Level', 'AllVidRabs', 'VrGroupsLinks'],
                $unmapped,
            );

            $coefficients[] = new GsfxCoefficient(
                caption: (string) $kNode['Caption'],
                code: $this->attr($kNode, 'Code'),
                options: $this->attr($kNode, 'Options'),
                value_oz: $this->attr($kNode, 'Value_OZ'),
                value_em: $this->attr($kNode, 'Value_EM'),
                value_zm: $this->attr($kNode, 'Value_ZM'),
                value_mt: $this->attr($kNode, 'Value_MT'),
                level: $this->attr($kNode, 'Level'),
                all_vid_rabs: $this->attr($kNode, 'AllVidRabs'),
                vr_groups_links: $this->attr($kNode, 'VrGroupsLinks'),
            );
        }

        return $coefficients;
    }

    /**
     * @param  array<string, int>  $unmapped
     */
    public function parseIndexCatalog(SimpleXMLElement $xml, array &$unmapped): ?GsfxIndexCatalog
    {
        if (! isset($xml->Indexes)) {
            return null;
        }

        $indexesNode = $xml->Indexes;
        $this->tallyUnreadAttributes($indexesNode, 'Indexes', [], $unmapped);

        return new GsfxIndexCatalog(
            positions: isset($indexesNode->IndexesPos)
                ? $this->parseIndexSubCatalog($indexesNode->IndexesPos, 'IndexesPos', $unmapped)
                : [],
            resources: isset($indexesNode->IndexesRes)
                ? $this->parseIndexSubCatalog($indexesNode->IndexesRes, 'IndexesRes', $unmapped)
                : [],
        );
    }

    /**
     * @param  list<string>  $read
     * @param  array<string, int>  $unmapped
     */
    public function tallyUnreadAttributes(SimpleXMLElement $node, string $tag, array $read, array &$unmapped): void
    {
        foreach ($node->attributes() as $name => $value) {
            $name = (string) $name;

            if (! in_array($name, $read, true)) {
                $this->tally($unmapped, "{$tag}@{$name}");
            }
        }
    }

    /**
     * @param  array<string, int>  $unmapped
     */
    public function tally(array &$unmapped, string $tag): void
    {
        $unmapped[$tag] = ($unmapped[$tag] ?? 0) + 1;
    }

    public function attr(SimpleXMLElement $node, string $name): ?string
    {
        return isset($node[$name]) ? (string) $node[$name] : null;
    }

    /**
     * @param  array<string, int>  $unmapped
     * @return array<string, GsfxIndex>
     */
    private function parseIndexSubCatalog(SimpleXMLElement $subCatalogNode, string $tag, array &$unmapped): array
    {
        $this->tallyUnreadAttributes($subCatalogNode, $tag, [], $unmapped);

        $entries = [];

        foreach ($subCatalogNode->Index ?? [] as $indexNode) {
            $this->tallyUnreadAttributes($indexNode, 'Index', ['Code', 'Caption', 'MT', 'EM'], $unmapped);

            $code = (string) $indexNode['Code'];

            if (isset($entries[$code])) {
                $this->tally($unmapped, 'Indexes@DuplicateCode');

                continue;
            }

            $entries[$code] = new GsfxIndex(
                code: $code,
                caption: (string) $indexNode['Caption'],
                mt: $this->attr($indexNode, 'MT'),
                em: $this->attr($indexNode, 'EM'),
            );
        }

        return $entries;
    }

    /**
     * @param  array<string, int>  $unmapped
     */
    private function parsePosition(SimpleXMLElement $positionNode, array &$unmapped): GsfxPosition
    {
        $this->tallyUnreadAttributes(
            $positionNode,
            'Position',
            ['Caption', 'Number', 'Code', 'Units', 'Quantity', 'IndexCode', 'Vr2001'],
            $unmapped,
        );

        $resources = [];
        $coefficients = [];
        $priceBase = null;
        $priceCurr = null;
        $quantity = $this->attr($positionNode, 'Quantity');

        foreach ($positionNode->children() as $child) {
            $name = $child->getName();

            match ($name) {
                'Resources' => $resources = $this->parseResources($child, $unmapped),
                'Koefficients' => $coefficients = $this->parseCoefficients($child, $unmapped),
                'Quantity' => $quantity = $this->readQuantityResult($child, $quantity, $unmapped),
                'PriceBase' => $priceBase = $this->priceValue($child, $unmapped),
                'PriceCurr' => $priceCurr = $this->priceValue($child, $unmapped),
                default => $this->tally($unmapped, $name),
            };
        }

        $indices = [];
        foreach ($resources as $resource) {
            if ($resource->index_code !== null && ! in_array($resource->index_code, $indices, true)) {
                $indices[] = $resource->index_code;
            }
        }

        return new GsfxPosition(
            name: (string) $positionNode['Caption'],
            number: $this->attr($positionNode, 'Number'),
            code: $this->attr($positionNode, 'Code'),
            unit: $this->attr($positionNode, 'Units'),
            quantity: $quantity,
            index_code: $this->attr($positionNode, 'IndexCode'),
            work_type_id: $this->attr($positionNode, 'Vr2001'),
            price_base: $priceBase,
            price_curr: $priceCurr,
            indices: $indices,
            coefficients: $coefficients,
            resources: $resources,
        );
    }

    /**
     * @param  array<string, int>  $unmapped
     */
    private function readQuantityResult(SimpleXMLElement $quantityNode, ?string $fallback, array &$unmapped): ?string
    {
        $this->tallyUnreadAttributes($quantityNode, 'Quantity', ['Result'], $unmapped);

        return $this->attr($quantityNode, 'Result') ?? $fallback;
    }

    /**
     * @param  array<string, int>  $unmapped
     * @return list<GsfxResource>
     */
    private function parseResources(SimpleXMLElement $resourcesNode, array &$unmapped): array
    {
        $this->tallyUnreadAttributes($resourcesNode, 'Resources', [], $unmapped);

        $resources = [];
        $knownTypes = ['Mat', 'Mch', 'Work', 'Tzr', 'Tzm'];

        foreach ($resourcesNode->children() as $child) {
            $name = $child->getName();

            if (! in_array($name, $knownTypes, true)) {
                $this->tally($unmapped, $name);

                continue;
            }

            $this->tallyUnreadAttributes(
                $child,
                $name,
                ['Caption', 'Code', 'Units', 'Quantity', 'IndexCode'],
                $unmapped,
            );

            $priceBase = null;
            $priceCurr = null;
            $tzm2022 = null;
            foreach ($child->children() as $subNode) {
                match ($subNode->getName()) {
                    'PriceBase' => $priceBase = $this->priceValue($subNode, $unmapped),
                    'PriceCurr' => $priceCurr = $this->priceValue($subNode, $unmapped),
                    'Tzm2022' => $tzm2022 = $this->parseTzm2022($subNode, $unmapped),
                    default => $this->tally($unmapped, $subNode->getName()),
                };
            }

            $resources[] = new GsfxResource(
                resource_type: $name,
                name: (string) $child['Caption'],
                code: $this->attr($child, 'Code'),
                unit: $this->attr($child, 'Units'),
                quantity: $this->attr($child, 'Quantity'),
                price_base: $priceBase,
                price_curr: $priceCurr,
                index_code: $this->attr($child, 'IndexCode'),
                tzm2022: $tzm2022,
            );
        }

        return $resources;
    }

    /**
     * @param  array<string, int>  $unmapped
     */
    private function parseTzm2022(SimpleXMLElement $node, array &$unmapped): GsfxTzm2022
    {
        $this->tallyUnreadAttributes($node, 'Tzm2022', ['WorkClass', 'Quantity', 'Price'], $unmapped);

        return new GsfxTzm2022(
            work_class: $this->attr($node, 'WorkClass'),
            quantity: $this->attr($node, 'Quantity'),
            price: $this->attr($node, 'Price'),
        );
    }

    /**
     * @param  array<string, int>  $unmapped
     */
    private function priceValue(SimpleXMLElement $priceNode, array &$unmapped): ?string
    {
        $this->tallyUnreadAttributes($priceNode, $priceNode->getName(), ['Value', 'MT', 'PZ'], $unmapped);

        return $this->attr($priceNode, 'Value') ?? $this->attr($priceNode, 'MT') ?? $this->attr($priceNode, 'PZ');
    }
}
