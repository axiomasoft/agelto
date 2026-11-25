<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Gsfx;

use App\Dto\Procurement\Prepare\Gsfx\GsfxAddZatr;
use App\Dto\Procurement\Prepare\Gsfx\GsfxAddZatrGlava;
use App\Dto\Procurement\Prepare\Gsfx\GsfxDocument;
use App\Dto\Procurement\Prepare\Gsfx\GsfxPassport;
use App\Dto\Procurement\Prepare\Gsfx\GsfxWorkType;
use App\Dto\Procurement\Prepare\Gsfx\GsfxWorkTypeGroup;
use SimpleXMLElement;

/**
 * Разбор `Properties.txt` + `Data.xml` контейнера ГрандСметы в {@see GsfxDocument}.
 * Кодировка обеих частей — `windows-1251` явно объявленная, без автодетекции. XML небезопасен по
 * умолчанию (XXE): `DOCTYPE`/`ENTITY` отклоняются ДО парсинга явной проверкой байт, парсинг —
 * `LIBXML_NONET` без `LIBXML_NOENT`/`LIBXML_DTDLOAD` (внешние сущности не резолвятся).
 */
final readonly class GsfxReader
{
    public function __construct(
        private GsfxXmlParser $xmlParser = new GsfxXmlParser,
    ) {}

    /**
     * @throws GsfxUnreadableException Битый/небезопасный XML.
     */
    public function parse(string $propertiesRaw, string $dataXmlRaw): GsfxDocument
    {
        $this->guardAgainstXxe($dataXmlRaw);

        $xml = $this->loadXml($this->decode($dataXmlRaw));

        $unmapped = [];

        $this->xmlParser->tallyUnreadAttributes($xml, 'Document', [], $unmapped);

        $chapters = $this->xmlParser->parseChapters($xml, $unmapped);
        $workTypeGroups = $this->parseWorkTypeGroups($xml, $unmapped);
        $addZatrGlavas = $this->parseAddZatrGlavas($xml, $unmapped);
        $indexCatalog = $this->xmlParser->parseIndexCatalog($xml, $unmapped);
        $coefficients = [];
        foreach ($xml->Koefficients ?? [] as $koefficientsNode) {
            $coefficients = [...$coefficients, ...$this->xmlParser->parseCoefficients($koefficientsNode, $unmapped)];
        }
        $this->tallyUnmappedTopLevel($xml, $unmapped);

        return new GsfxDocument(
            passport: $this->parsePassport($this->decode($propertiesRaw)),
            chapters: $chapters,
            work_type_groups: $workTypeGroups,
            add_zatr_glavas: $addZatrGlavas,
            index_catalog: $indexCatalog,
            coefficients: $coefficients,
            unmapped: $unmapped,
        );
    }

    private function guardAgainstXxe(string $rawXml): void
    {
        if (stripos($rawXml, '<!DOCTYPE') !== false || stripos($rawXml, '<!ENTITY') !== false) {
            throw new GsfxUnreadableException('Data.xml несёт DOCTYPE/ENTITY — отклонено (XXE)');
        }
    }

    private function decode(string $raw): string
    {
        $withoutBom = (string) preg_replace('/^\xEF\xBB\xBF/', '', $raw, limit: 1);

        if ($withoutBom !== $raw || $this->hasUtf8Prolog($withoutBom) || mb_check_encoding($withoutBom, 'UTF-8')) {
            return $withoutBom;
        }

        return mb_convert_encoding($withoutBom, 'UTF-8', 'Windows-1251');
    }

    private function hasUtf8Prolog(string $raw): bool
    {
        return preg_match('/^\s*(?:\xEF\xBB\xBF)?<\?xml\b[^>]*\bencoding\s*=\s*["\']utf-?8["\'][^>]*\?>/i', $raw) === 1;
    }

    private function loadXml(string $utf8Xml): SimpleXMLElement
    {
        $withoutProlog = (string) preg_replace('/^\s*(?:\xEF\xBB\xBF)?<\?xml[^>]*\?>/i', '', $utf8Xml, limit: 1);

        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($withoutProlog, options: LIBXML_NONET);
            $errors = libxml_get_errors();
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            $detail = $errors === [] ? 'неизвестная ошибка XML' : trim($errors[0]->message);
            throw new GsfxUnreadableException("Data.xml не парсится: {$detail}");
        }

        return $xml;
    }

    private function parsePassport(string $propertiesUtf8): GsfxPassport
    {
        $pairs = [];
        $lines = preg_split('/\r\n|\r|\n/', $propertiesUtf8);

        if ($lines === false) {
            throw new GsfxUnreadableException('Properties.txt не разбивается на строки.');
        }

        foreach ($lines as $line) {
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
     * @return list<GsfxWorkTypeGroup>
     */
    private function parseWorkTypeGroups(SimpleXMLElement $xml, array &$unmapped): array
    {
        $groups = [];

        if (isset($xml->VidRab_Catalog)) {
            $this->xmlParser->tallyUnreadAttributes($xml->VidRab_Catalog, 'VidRab_Catalog', [], $unmapped);
        }

        foreach ($xml->VidRab_Catalog->Vids_Rab ?? [] as $blockNode) {
            $this->xmlParser->tallyUnreadAttributes($blockNode, 'Vids_Rab', ['Type'], $unmapped);

            $catalogType = $this->xmlParser->attr($blockNode, 'Type');

            foreach ($blockNode->VidRab_Group ?? [] as $groupNode) {
                $this->xmlParser->tallyUnreadAttributes($groupNode, 'VidRab_Group', ['Caption', 'ID'], $unmapped);

                $workTypes = [];

                foreach ($groupNode->Vid_Rab ?? [] as $vidRabNode) {
                    $this->xmlParser->tallyUnreadAttributes(
                        $vidRabNode,
                        'Vid_Rab',
                        ['Caption', 'ID', 'Category', 'NrCode', 'SpCode', 'Nacl', 'NaclCurr', 'Plan', 'PlanCurr'],
                        $unmapped,
                    );

                    $workTypes[] = new GsfxWorkType(
                        caption: (string) $vidRabNode['Caption'],
                        id: $this->xmlParser->attr($vidRabNode, 'ID'),
                        category: $this->xmlParser->attr($vidRabNode, 'Category'),
                        nr_code: $this->xmlParser->attr($vidRabNode, 'NrCode'),
                        sp_code: $this->xmlParser->attr($vidRabNode, 'SpCode'),
                        nacl: $this->xmlParser->attr($vidRabNode, 'Nacl'),
                        nacl_curr: $this->xmlParser->attr($vidRabNode, 'NaclCurr'),
                        plan: $this->xmlParser->attr($vidRabNode, 'Plan'),
                        plan_curr: $this->xmlParser->attr($vidRabNode, 'PlanCurr'),
                    );
                }

                $groups[] = new GsfxWorkTypeGroup(
                    caption: (string) $groupNode['Caption'],
                    id: $this->xmlParser->attr($groupNode, 'ID'),
                    catalog_type: $catalogType,
                    work_types: $workTypes,
                );
            }
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
            $this->xmlParser->tallyUnreadAttributes($xml->AddZatrats, 'AddZatrats', [], $unmapped);
        }

        foreach ($xml->AddZatrats->AddZatrGlava ?? [] as $glavaNode) {
            $this->xmlParser->tallyUnreadAttributes($glavaNode, 'AddZatrGlava', ['Glava'], $unmapped);

            $items = [];

            foreach ($glavaNode->AddZatr ?? [] as $addZatrNode) {
                $this->xmlParser->tallyUnreadAttributes(
                    $addZatrNode,
                    'AddZatr',
                    ['Caption', 'Value', 'Formula', 'Options'],
                    $unmapped,
                );

                $items[] = new GsfxAddZatr(
                    caption: (string) $addZatrNode['Caption'],
                    value: $this->xmlParser->attr($addZatrNode, 'Value'),
                    formula: $this->xmlParser->attr($addZatrNode, 'Formula'),
                    options: $this->xmlParser->attr($addZatrNode, 'Options'),
                );
            }

            $glavas[] = new GsfxAddZatrGlava(
                glava: $this->xmlParser->attr($glavaNode, 'Glava'),
                items: $items,
            );
        }

        return $glavas;
    }

    /**
     * @param  array<string, int>  $unmapped
     */
    private function tallyUnmappedTopLevel(SimpleXMLElement $xml, array &$unmapped): void
    {
        $handled = ['Properties', 'Attachments', 'VidRab_Catalog', 'AddZatrats', 'Chapters', 'Indexes', 'Koefficients'];

        foreach ($xml->children() as $child) {
            $name = $child->getName();

            if (! in_array($name, $handled, true)) {
                $this->xmlParser->tally($unmapped, $name);
            }
        }
    }
}
