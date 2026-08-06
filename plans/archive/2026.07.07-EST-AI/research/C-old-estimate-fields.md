# Отчёт C — инвентаризация полей смет/документов Old/ (v1)

ГЛАВНОЕ: отдельной таблицы смет в v1 НЕТ — всё в jsonb plt_document_analysis_responses.data/state. ДВА параллельных семейства схем: legacy Excel (Dto/Estimates) и новый structured (Process/EstimateAnalysis/Schema).

## 1A. Legacy-контракт (app/Dto/Estimates/** + SmetaParserService::parse, gemini-2.0-flash сырой Guzzle)
- Estimate: is_candidate bool; header Header?; items DataCollection<Item>?
- Header: columns_count int; start_row_index/end_row_index (Excel, +1); start_col_index/end_col_index; schema array — ДИНАМИЧЕСКАЯ карта колонок [{cell_index, code}] от ИИ
- Item: row_index int; type ItemType(section/item); section_title string? (протягивается); fields array — динамические ключи по schema[].code
Особенность: набор полей позиции НЕ фиксирован — ИИ придумывает коды колонок под файл.

## 1B. Structured-контракт (Process/EstimateAnalysis/Schema/**), шаги preview → fetch_info → fetch_items
- EstimatePassport: estimate_id string; type EstimateType?; reference string?; target EstimateTarget?; address string?
- EstimatePreview: passport; items_count int; items_count_total int; page_start int; page_end int
- EstimateFetchInfo: estimate_id; generator string? (сметная программа, напр. Гранд-смета); header EstimateHeader; footer EstimateFooterItem[]
- EstimateHeader (поля с #[Alias]): pos(№ п/п,№,поз), code(Шифр,Обоснование,Код), name(Наименование работ,Описание), unit(Ед. изм.,Измеритель), quantity(Количество,Объем), price(Стоимость всего,Итого руб) — значения EstimateHeaderColumn{index int (с 0), name (текст ячейки), full_name (с родительской группой)}
- Дерево (EstimateFetchItems.data): EstimateSection{type='section', name, children:(EstimateSubSection|EstimateItem)[]} · EstimateSubSection{type='subsection', name, children:<EstimateItem>} · EstimateItem{name!, pos int?, code string?, unit?, quantity float?, price float?, children:<EstimateItemResource>, modifiers:<EstimateModifierItem>} · EstimateItemResource{name, resource_type EstimateResourceType, code?, unit?, quantity?, price?, comment? (примечание из колонки pos)} · EstimateModifierItem{name (код), description (обоснование), value float} · EstimateFooterItem{label, value float, children:[] рекурсия «в том числе»}
- Обёртки: *Response = {success bool, message string?, data}. БИТЫЙ: EstimateFetchItemsResponse ссылается на несуществующий namespace App\Process\DocumentAnalysis\Schema\Estimate\*.

## 2. Таблицы
plt_document_analysis (HasMedia): id; model_type/model_id nullable (morphTo documentAnalysable → LinkAttachment); parent_id→self cascade (дети ZIP); name varchar(500); extension varchar(12); file_fingerprint (md5 имя+размер+mtime); source varchar(500) index; gemini_cache_id, gemini_rules_hash, gemini_cache_expires_at; options json; status varchar(40) def preparing; completed_at; attempts/last_error/last_error_at/next_retry_at/failed_permanently; locked_by/locked_until. Unique (model_type,model_id,source); index (status,next_retry_at),(status,updated_at). Media: document-source, document-prepared (приоритет в getDocumentForAnalysis()).
plt_document_analysis_responses: document_analysis_id FK; parent_id→self (стадийность); prompt text; step (enum Step); cache_file_id index; rules_hash; prompt/completion/total_tokens; success bool def true; message; data json (СМЕТЫ ЗДЕСЬ); state json (напр. список смет к дообработке).
plt_document_analysis_logs: document_analysis_id; status varchar(40) index; message varchar(300); content json; trace text. Пишется на каждом transitionStatus.
media (spatie): custom_properties.google_file_uri.
plt_crawler_link_attachments (LinkAttachment): link_id; external_id varchar(60); name(500); extension(12); url(1000); content_hash(64) index; status(20) def pending; error-блок; downloaded_at; unique(link_id, external_id). documentAnalyses() morphMany.
ord_orders: reg_number, edition_number, name, status, customer_id, platform_id, initial_price dec(20,2), currency(3), order_published/updated/submission_start/end/scoring_at.
ord_lots: order_id, lot_number, subject, okved(14), okpd2(20), is_centralized, initial_price, dirty_data json.
ord_purchase_objects: order_id, okpd2(20), name, quantity dec(15,3), unit(100), price dec(15,2), total_cost dec(15,2).
ord_lot_infos: lot_id, okved_text, okpd2_text, unit, quantity, unit_price, total_price, additional_info (string).

## 3. Форматы файлов
- ZIP (ArchiveStrategy): ZipArchive → storage/app/temp_extract/{uuid}; каждый файл → дочерний DocumentAnalysis (parent_id, копия model_type/model_id), медиа document-source; пропуск директорий/dot-файлов; firstOrCreate (parent_id+name); temp удаляется.
- XLS/XLSX (ExcelStrategy): PhpSpreadsheet+Mpdf → PDF landscape fit-to-width → document-prepared; skip если prepared есть.
- DOCX/изображения/PDF: стратегий НЕТ — как есть, OCR/парсинг делает Gemini мультимодально.
- Gemini File API: ensureFileInCloud (ACTIVE до 5 мин, sleep 3с), URI в media custom_properties. getContextCache: gemini-1.5-flash, TTL 6ч. generateJson: responseMimeType JSON, temp 0.1, Redis::throttle. Прокси sys.proxies.gemini_proxy.
- Локальной OCR/PDF→картинки НЕТ.

## 4. Enum'ы
ItemType: section, item. EstimateType: estimate(ЛСР), act(КС-2), resource_calculation(Ведомость ресурсов), defect_list(Дефектная ведомость). EstimateTarget: construction, renovation, medical_equipment, engineering. EstimateResourceType: Material(МАТ/ФССЦ/прайс), Machine(ЭМ/маш.час/ФСЭМ), Labor(ЗП/ОТ/ЗПМ/чел.час), Overhead(НР/СП/Прибыль). DocumentType (скрининг): estimate, tech_spec, unknown (≠ EstimateType!). AnalysisStatus: см. отчёт A. Step: см. отчёт B. PrimaryAnalysis.data: document_type, description?, tags[] (Город, ОС, Шифр, Вид работ, ФИО).

## 5. Легко потерять
- Контракт формата смет живёт ТОЛЬКО в классах Old + jsonb — при удалении Old/ теряется.
- Два семейства схем (динамические fields vs фиксированные).
- #[Alias]-атрибут (app/Attributes/Alias.php) на EstimateHeader/EstimateResourceType — матчинг русских заголовков.
- EstimateAnalysisProcessor-заглушка: реально пишет только fetch_items; preview/fetch_info не реализованы; паспорт/footer спроектированы, но не заполняются.
- uploadFile() mimeType по умолчанию application/pdf БЕЗ реального mime → docx/картинки уходят как PDF (потенциальный баг).
- Guards: unique (model_type,model_id,source); firstOrCreate ZIP (parent_id,name); lease TTL 600с; job status-guard.
- recordFailure: backoff min(2^n,240) мин, failed_permanently ≥5.
- file_fingerprint при изменении сбрасывает gemini_cache_id/expires_at.
- getDocumentForAnalysis(): prepared > source.
- recomputeStatusFromAnalyses() на Link.
- generator (сметная программа) в EstimateFetchInfo.
- config('platform.estimate.ignore_rules') — фильтр «Поставка продуктов питания» по subject.
- Токены в responses; промпты в plt_prompt_rules (БД, не в коде!).
