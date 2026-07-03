<?php

declare(strict_types=1);

namespace App\Services;

use App\Dto\Estimates\Estimate;
use App\Dto\Estimates\Header;
use App\Dto\Estimates\Item;
use App\Enums\Estimates\ItemType;
use Exception;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class SmetaParserService
{
    private GuzzleClient $client;

    private string $model = 'gemini-2.0-flash';

    public function __construct()
    {
        $this->client = new GuzzleClient([
            'proxy' => config('gemini.proxy'),
            'verify' => false,
            'timeout' => 120,
        ]);
    }

    /**
     * ГЛАВНЫЙ МЕТОД: Полный цикл анализа и парсинга
     */
    public function parse(string $filePath): Estimate
    {
        Log::info('--- СТАРТ ПОЛНОГО ПАРСИНГА ---', ['file' => $filePath]);

        // 1. Загружаем Excel один раз для всех нужд
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $allRows = $sheet->toArray(null, true, true, false);

        // 2. Анализируем только шапку (передаем первые 100 строк)
        $header = $this->parseHeaderFromRows(array_slice($allRows, 0, 100));

        if (! $header) {
            Log::error('Парсинг прерван: Шапка не распознана ИИ или не найдена в массиве.');

            return Estimate::from([
                'is_candidate' => false,
                'header' => null,
                'items' => null,
            ]);
        }

        // 3. Парсинг данных по чанкам (начиная со строки после шапки)
        $dataStartRow = $header->end_row_index;
        $usefulRows = array_slice($allRows, $dataStartRow, null, true);
        $chunks = array_chunk($usefulRows, 80, true);

        $items = [];
        $currentSection = 'Начало сметы';

        foreach ($chunks as $index => $chunk) {
            $firstRowIndex = key($chunk);
            $jsonData = $this->prepareCompactJson($chunk, $header->end_col_index);

            Log::info("Обработка чанка #$index", ['start_row' => $firstRowIndex + 1]);
            $aiData = $this->askGeminiForData($jsonData, $currentSection, $header->schema);

            foreach ($aiData as $i => $rowAi) {
                $type = (isset($rowAi['_is_section']) && $rowAi['_is_section'])
                    ? ItemType::SECTION
                    : ItemType::ITEM;

                if ($type === ItemType::SECTION) {
                    $currentSection = $rowAi['_section_text'] ?? $currentSection;
                }

                $items[] = Item::from([
                    'row_index' => $firstRowIndex + $i + 1,
                    'type' => $type,
                    'section_title' => $currentSection,
                    'fields' => $type === ItemType::ITEM ? $this->mapFields($rowAi, $header->schema) : [],
                ]);
            }
            usleep(500000);
        }

        Log::info('--- ПАРСИНГ ЗАВЕРШЕН ---', ['total_items' => count($items)]);

        return Estimate::from([
            'is_candidate' => true,
            'header' => $header,
            'items' => $items,
        ]);
    }

    /**
     * Метод анализа шапки (принимает массив строк)
     */
    public function parseHeaderFromRows(array $rows): Header|false
    {
        Log::info('=== Анализ шапки через HTML-представление ===');

        $htmlForAi = $this->convertRowsToSimpleHtml($rows);
        $aiAnalysis = $this->getAiHeaderFromHtml($htmlForAi);

        // Добавляем проверку на существование ключа после исправления формата
        if (empty($aiAnalysis) || ! isset($aiAnalysis['is_estimate']) || $aiAnalysis['is_estimate'] === false) {
            Log::warning('ИИ не подтвердил наличие сметы.');

            return false;
        }

        Log::info('Ответ ИИ по структуре (обработан):', ['ai_json' => $aiAnalysis]);

        $coords = $this->locateHeaderCoords($rows, $aiAnalysis);
        Log::info('Координаты в Excel:', ['coords' => $coords]);

        if (! $coords['found']) {
            Log::error("Не удалось найти текстовые якоря. Искали: '{$aiAnalysis['first_anchor']}' и '{$aiAnalysis['last_anchor']}'");

            return false;
        }

        return Header::from([
            'columns_count' => ($coords['end_col'] - $coords['start_col']) + 1,
            'start_row_index' => $coords['start_row'] + 1,
            'end_row_index' => $coords['end_row'] + 1,
            'start_col_index' => $coords['start_col'],
            'end_col_index' => $coords['end_col'],
            'schema' => $aiAnalysis['mapping'],
        ]);
    }

    private function getAiHeaderFromHtml(string $html): array
    {
        $prompt = "Проанализируй HTML-код начала документа. Это строительная смета?
        Найди строку заголовка таблицы (шапку). Обычно она содержит названия колонок.
        Верни СТРОГО JSON объект (не массив):
        {
          'is_estimate': true,
          'first_anchor': 'Текст самой первой ячейки ЗАГОЛОВКА (напр. № п/п)',
          'last_anchor': 'Текст самой последней (правой) ячейки того же ЗАГОЛОВКА (напр. Всего или Сумма)',
          'mapping': [ {'cell_index': 0, 'code': 'n'}, ... ]
        }
        Если это не смета, верни {'is_estimate': false}.
        ВАЖНО: last_anchor должен быть текстом из шапки таблицы, а не из строк с данными!";

        $rawResponse = $this->callGemini($prompt, $html);
        Log::debug('Сырой ответ Gemini (Header):', ['raw' => $rawResponse]);

        $data = json_decode($rawResponse, true) ?? [];

        // Исправление: если ИИ вернул массив вместо объекта, берем первый элемент
        if (isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        return $data;
    }

    private function locateHeaderCoords(array $rows, array $ai): array
    {
        $startRow = $endRow = $startCol = $endCol = 0;
        $foundStart = false;
        $firstM = mb_strtolower(mb_trim($ai['first_anchor'] ?? ''));
        $lastM = mb_strtolower(mb_trim($ai['last_anchor'] ?? ''));

        foreach ($rows as $rIdx => $row) {
            foreach ($row as $cIdx => $cell) {
                $val = mb_strtolower(mb_trim((string) $cell));
                if (! $foundStart && $val !== '' && str_contains($val, $firstM)) {
                    $startRow = $rIdx;
                    $startCol = $cIdx;
                    $foundStart = true;
                }
                if ($foundStart && $val !== '' && str_contains($val, $lastM)) {
                    $endRow = $rIdx;
                    $endCol = $cIdx;
                }
            }
        }

        // Захват строки с номерами 1, 2, 3...
        if ($foundStart && isset($rows[$endRow + 1])) {
            $next = array_values(array_filter($rows[$endRow + 1]));
            if (! empty($next) && (int) $next[0] === 1) {
                $endRow++;
            }
        }

        return ['found' => $foundStart, 'start_row' => $startRow, 'end_row' => $endRow, 'start_col' => $startCol, 'end_col' => $endCol];
    }

    private function convertRowsToSimpleHtml(array $rows): string
    {
        $html = '<table border="1">';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $val = htmlspecialchars(mb_trim((string) $cell));
                $html .= "<td>$val</td>";
            }
            $html .= '</tr>';
        }

        return $html.'</table>';
    }

    private function askGeminiForData(string $jsonData, string $section, array $schema): array
    {
        $schemaStr = json_encode($schema);
        $prompt = "Распарси JSON сметы. ТЕКУЩИЙ РАЗДЕЛ: '$section'. СХЕМА: $schemaStr.
        Если строка - заголовок раздела, верни {'_is_section': true, '_section_text': 'текст'}.
        Иначе объект. СТРОГО JSON массив.";

        $raw = $this->callGemini($prompt, $jsonData);

        return json_decode($raw, true) ?? [];
    }

    private function mapFields(array $rowAi, array $schema): array
    {
        $res = [];
        foreach ($schema as $col) {
            $res[$col['code']] = $rowAi[$col['code']] ?? null;
        }

        return $res;
    }

    private function prepareCompactJson(array $rows, int $limitCol): string
    {
        $res = [];
        foreach ($rows as $row) {
            $slice = array_slice(array_values($row), 0, $limitCol + 1);
            if (array_filter($slice, fn ($v) => ! is_null($v) && $v !== '')) {
                $res[] = array_map(fn ($v) => is_string($v) ? mb_strimwidth(mb_trim($v), 0, 400) : $v, $slice);
            }
        }

        return json_encode($res, JSON_UNESCAPED_UNICODE);
    }

    private function callGemini(string $prompt, string $data): string
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key=".config('gemini.api_key');
        try {
            $response = $this->client->post($url, [
                'json' => [
                    'contents' => [['parts' => [['text' => $prompt."\nDATA:\n".$data]]]],
                    'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0],
                ],
            ]);
            $body = json_decode($response->getBody()->getContents(), true);

            return $body['candidates'][0]['content']['parts'][0]['text'] ?? '[]';
        } catch (Exception $e) {
            Log::error('Gemini Error: '.$e->getMessage());

            return '[]';
        }
    }
}
