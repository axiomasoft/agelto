<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Gsfx;

use App\Dto\Procurement\Ai\Gsfx\GsfxAddZatr;
use App\Dto\Procurement\Ai\Gsfx\GsfxAddZatrGlava;
use App\Dto\Procurement\Ai\Gsfx\GsfxChapter;
use App\Dto\Procurement\Ai\Gsfx\GsfxDocument;
use App\Dto\Procurement\Ai\Gsfx\GsfxIndex;
use App\Dto\Procurement\Ai\Gsfx\GsfxIndexCatalog;
use App\Dto\Procurement\Ai\Gsfx\GsfxPassport;
use App\Dto\Procurement\Ai\Gsfx\GsfxPosition;
use App\Dto\Procurement\Ai\Gsfx\GsfxResource;
use App\Dto\Procurement\Ai\Gsfx\GsfxWorkType;
use App\Dto\Procurement\Ai\Gsfx\GsfxWorkTypeGroup;
use SimpleXMLElement;

/**
 * Разбор `Properties.txt` + `Data.xml` контейнера ГрандСметы в {@see GsfxDocument} (P3.3, D2).
 * Кодировка обеих частей — `windows-1251` явно объявленная, без автодетекции. XML небезопасен по
 * умолчанию (XXE): `DOCTYPE`/`ENTITY` отклоняются ДО парсинга явной проверкой байт, парсинг —
 * `LIBXML_NONET` без `LIBXML_NOENT`/`LIBXML_DTDLOAD` (внешние сущности не резолвятся).
 *
 * Элемент `Data.xml`, встретившийся, но не попавший в модель, копится в `unmapped[тег] += 1`
 * (Code Guidance P3.3) — на верхнем уровне документа и внутри `Chapter`/`Position`/`Resources`.
 * Атрибут узла, который ридер ОТКРЫВАЕТ, но не читает, копится в `unmapped["{$тег}@{$атрибут}"]`
 * (P3.8, D111) — узел, который ридер вообще не открывает, в атрибутный талль не разворачивается
 * (он уже виден элементным ключом). Ридер НЕ ТОЛКУЕТ: несёт код индекса и запись каталога как есть,
 * не выбирает составляющую `@MT`/`@EM` — это работа маппера P5.1 (D94, research/16 §2).
 */
final readonly class GsfxReader
{
    /**
     * @throws GsfxUnreadableException Битый/небезопасный XML.
     */
    public function parse(string $propertiesRaw, string $dataXmlRaw): GsfxDocument
    {
        $this->guardAgainstXxe($dataXmlRaw);

        $xml = $this->loadXml($this->decode($dataXmlRaw));

        $unmapped = [];

        $this->tallyUnreadAttributes($xml, 'Document', [], $unmapped);

        $chapters = $this->parseChapters($xml, $unmapped);
        $workTypeGroups = $this->parseWorkTypeGroups($xml, $unmapped);
        $addZatrGlavas = $this->parseAddZatrGlavas($xml, $unmapped);
        $indexCatalog = $this->parseIndexCatalog($xml, $unmapped);
        $this->tallyUnmappedTopLevel($xml, $unmapped);

        return new GsfxDocument(
            passport: $this->parsePassport($this->decode($propertiesRaw)),
            chapters: $chapters,
            work_type_groups: $workTypeGroups,
            add_zatr_glavas: $addZatrGlavas,
            index_catalog: $indexCatalog,
            unmapped: $unmapped,
        );
    }

    /**
     * Отказ ДО парсинга — надёжнее, чем полагаться исключительно на флаги libxml (версии
     * расходятся в дефолтах): контейнер приходит из недоверенной закупки.
     */
    private function guardAgainstXxe(string $rawXml): void
    {
        if (stripos($rawXml, '<!DOCTYPE') !== false || stripos($rawXml, '<!ENTITY') !== false) {
            throw new GsfxUnreadableException('Data.xml несёт DOCTYPE/ENTITY — отклонено (XXE)');
        }
    }

    private function decode(string $raw): string
    {
        return mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
    }

    private function loadXml(string $utf8Xml): SimpleXMLElement
    {
        // Пролог объявлял windows-1251 — контент уже перекодирован в UTF-8, пролог сносим, чтобы
        // libxml не пытался интерпретировать УЖЕ UTF-8 байты как cp1251 повторно.
        $withoutProlog = (string) preg_replace('/^<\?xml[^>]*\?>/i', '', $utf8Xml, limit: 1);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($withoutProlog, options: LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        if ($xml === false) {
            $detail = $errors === [] ? 'неизвестная ошибка XML' : trim($errors[0]->message);
            throw new GsfxUnreadableException("Data.xml не парсится: {$detail}");
        }

        return $xml;
    }

    private function parsePassport(string $propertiesUtf8): GsfxPassport
    {
        $pairs = [];
        foreach (preg_split('/\r\n|\r|\n/', $propertiesUtf8) as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $pairs[trim($key)] = trim($value);
        }

        return new GsfxPassport(
            doc_type: $pairs['DocType'] ?? null,
            loc_num: $pairs['Attribs.LocNum'] ?? null,
            object_name: $pairs['Attribs.ObjName'] ?? null,
            total: $pairs['Total'] ?? null,
            calc_method: $pairs['TotalCalcMethod'] ?? null,
            normative_base: $pairs['RegionInfo.RegionName'] ?? null,
            program_version: $pairs['GSmetaVersion'] ?? null,
            modified_at: $pairs['ModifyDate'] ?? null,
        );
    }

    /**
     * @param  array<string, int>  $unmapped
     * @return list<GsfxChapter>
     */
    private function parseChapters(SimpleXMLElement $xml, array &$unmapped): array
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
     */
    private function parsePosition(SimpleXMLElement $positionNode, array &$unmapped): GsfxPosition
    {
        $this->tallyUnreadAttributes(
            $positionNode,
            'Position',
            ['Caption', 'Number', 'Code', 'Units', 'Quantity', 'IndexCode'],
            $unmapped,
        );

        $resources = [];
        $priceBase = null;
        $priceCurr = null;
        $quantity = $this->attr($positionNode, 'Quantity');

        foreach ($positionNode->children() as $child) {
            $name = $child->getName();

            match ($name) {
                'Resources' => $resources = $this->parseResources($child, $unmapped),
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
            price_base: $priceBase,
            price_curr: $priceCurr,
            indices: $indices,
            coefficients: [],
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
            foreach ($child->children() as $priceNode) {
                match ($priceNode->getName()) {
                    'PriceBase' => $priceBase = $this->priceValue($priceNode, $unmapped),
                    'PriceCurr' => $priceCurr = $this->priceValue($priceNode, $unmapped),
                    default => $this->tally($unmapped, $priceNode->getName()),
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
            );
        }

        return $resources;
    }

    /**
     * @param  array<string, int>  $unmapped
     * @return list<GsfxWorkTypeGroup>
     */
    private function parseWorkTypeGroups(SimpleXMLElement $xml, array &$unmapped): array
    {
        $groups = [];

        if (isset($xml->VidRab_Catalog)) {
            $this->tallyUnreadAttributes($xml->VidRab_Catalog, 'VidRab_Catalog', [], $unmapped);
        }

        if (isset($xml->VidRab_Catalog->Vids_Rab)) {
            $this->tallyUnreadAttributes($xml->VidRab_Catalog->Vids_Rab, 'Vids_Rab', [], $unmapped);
        }

        foreach ($xml->VidRab_Catalog->Vids_Rab->VidRab_Group ?? [] as $groupNode) {
            $this->tallyUnreadAttributes($groupNode, 'VidRab_Group', ['Caption', 'ID'], $unmapped);

            $workTypes = [];

            foreach ($groupNode->Vid_Rab ?? [] as $vidRabNode) {
                $this->tallyUnreadAttributes(
                    $vidRabNode,
                    'Vid_Rab',
                    ['Caption', 'ID', 'Category', 'NrCode', 'SpCode'],
                    $unmapped,
                );

                $workTypes[] = new GsfxWorkType(
                    caption: (string) $vidRabNode['Caption'],
                    id: $this->attr($vidRabNode, 'ID'),
                    category: $this->attr($vidRabNode, 'Category'),
                    nr_code: $this->attr($vidRabNode, 'NrCode'),
                    sp_code: $this->attr($vidRabNode, 'SpCode'),
                );
            }

            $groups[] = new GsfxWorkTypeGroup(
                caption: (string) $groupNode['Caption'],
                id: $this->attr($groupNode, 'ID'),
                work_types: $workTypes,
            );
        }

        return $groups;
    }

    /**
     * @param  array<string, int>  $unmapped
     * @return list<GsfxAddZatrGlava>
     */
    private function parseAddZatrGlavas(SimpleXMLElement $xml, array &$unmapped): array
    {
        $glavas = [];

        if (isset($xml->AddZatrats)) {
            $this->tallyUnreadAttributes($xml->AddZatrats, 'AddZatrats', [], $unmapped);
        }

        foreach ($xml->AddZatrats->AddZatrGlava ?? [] as $glavaNode) {
            $this->tallyUnreadAttributes($glavaNode, 'AddZatrGlava', ['Glava'], $unmapped);

            $items = [];

            foreach ($glavaNode->AddZatr ?? [] as $addZatrNode) {
                $this->tallyUnreadAttributes(
                    $addZatrNode,
                    'AddZatr',
                    ['Caption', 'Value', 'Formula', 'Options'],
                    $unmapped,
                );

                $items[] = new GsfxAddZatr(
                    caption: (string) $addZatrNode['Caption'],
                    value: $this->attr($addZatrNode, 'Value'),
                    formula: $this->attr($addZatrNode, 'Formula'),
                    options: $this->attr($addZatrNode, 'Options'),
                );
            }

            $glavas[] = new GsfxAddZatrGlava(
                glava: $this->attr($glavaNode, 'Glava'),
                items: $items,
            );
        }

        return $glavas;
    }

    /**
     * Каталог `Document/Indexes` — ТОЛЬКО прямой потомок корня (не рекурсивный поиск): у акта
     * (`ImplemActs/ImplemAct/Indexes`) собственный каталог, склейка с ним недопустима (research/16
     * §3). Два подкаталога (`IndexesPos`/`IndexesRes`) — РАЗДЕЛЬНЫЕ пространства кодов: одинаковый
     * `@Code` в разных подкаталогах — разные записи (findings §2).
     *
     * @param  array<string, int>  $unmapped
     */
    private function parseIndexCatalog(SimpleXMLElement $xml, array &$unmapped): ?GsfxIndexCatalog
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
    private function tallyUnmappedTopLevel(SimpleXMLElement $xml, array &$unmapped): void
    {
        // `Properties` дублирует паспорт из Properties.txt (тем же смыслом) — не теряем данные,
        // не считаем unmapped. `Attachments` — идёт в дочерние документы отдельным механизмом
        // (GsfxStrategy), тоже не пропажа.
        $handled = ['Properties', 'Attachments', 'VidRab_Catalog', 'AddZatrats', 'Chapters', 'Indexes'];

        foreach ($xml->children() as $child) {
            $name = $child->getName();

            if (! in_array($name, $handled, true)) {
                $this->tally($unmapped, $name);
            }
        }
    }

    /**
     * @param  array<string, int>  $unmapped
     */
    private function tally(array &$unmapped, string $tag): void
    {
        $unmapped[$tag] = ($unmapped[$tag] ?? 0) + 1;
    }

    /**
     * Талль АТРИБУТОВ узла, который ридер ОТКРЫВАЕТ, но которых нет в `$read` (P3.8, D111) — ключ
     * `"{$tag}@{$name}"`. Вызывается В КАЖДОМ месте, где ридер открывает узел, со списком атрибутов,
     * прочитанных ИМЕННО ТАМ; узлы, которые ридер не открывает вовсе, сюда не попадают — они уже
     * видны элементным тальём.
     *
     * @param  list<string>  $read
     * @param  array<string, int>  $unmapped
     */
    private function tallyUnreadAttributes(SimpleXMLElement $node, string $tag, array $read, array &$unmapped): void
    {
        foreach ($node->attributes() as $name => $value) {
            $name = (string) $name;

            if (! in_array($name, $read, true)) {
                $this->tally($unmapped, "{$tag}@{$name}");
            }
        }
    }

    private function attr(SimpleXMLElement $node, string $name): ?string
    {
        return isset($node[$name]) ? (string) $node[$name] : null;
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
