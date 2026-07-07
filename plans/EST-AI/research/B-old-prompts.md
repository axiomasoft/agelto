# Отчёт B — промт-подсистема и Gemini-интеграция Old/ (v1)

ВАЖНО: тексты промтов в коде/сидерах ОТСУТСТВУЮТ — только в БД plt_prompt_rules (Filament MarkdownEditor). Единственный носитель текстов в репо — Old/docs/03_Dev/LLM_Strategy.md (раздел 10) + PHP-исходники Schema-классов, подмешиваемые в system instruction.

## plt_prompt_rules (Models/Prompt/PromptRule.php, миграция 2026_02_19_073335)
key (string, index), step (string, cast enum Step), content (text, Markdown), version (int, def 1), priority (int, def 100, МЕНЬШЕ = ВЫШЕ в промте, orderBy ASC; коммент миграции врёт), is_active (bool, scope active()), unique(key, version).

## Enum Step (Dto/Prompt/Step.php), формат process:phase
global · document_analysis:global · document_analysis:primary · estimate_analysis:global · estimate_analysis:preview · estimate_analysis:fetch_items · estimate_analysis:fetch_info

## Поток
A. Prepare: ExcelStrategy xls/xlsx→PDF (PhpSpreadsheet+Mpdf, landscape, fitToWidth=1) → коллекция document-prepared; getDocumentForAnalysis() отдаёт prepared, иначе source — Gemini всегда получает PDF.
B. Upload: GeminiService::ensureFileInCloud — fingerprint = md5(file_name+filesize+filemtime); URI в custom-property Media google_file_uri; Files API upload → polling metadataGet каждые 3с до ACTIVE (таймаут 5 мин; FAILED→exception; UnserializableResponse от прокси→retry).
C. Композиция system instruction (PromptRuleRepository::systemInstructionsFor(Step)):
  1) шаги всегда [global, <group:global>, <step>]
  2) из БД активные, orderByRaw FIELD(step,...), внутри priority ASC, склейка "\n\n---\n\n"
  3) в конец — Schema-исходники (buildSchemaSourcesForSteps): все .php из app/Process/<Proc>/Schema/**, Responses/ строго последними (usort); каждый предваряется "// --- Source: <basename> ---"
D. Вызов: generateJson — модель gemini-2.0-flash, GenerationConfig(responseMimeType=APPLICATION_JSON, temperature=0.1) — JSON mode БЕЗ responseSchema (схема текстом). Первичный: без кэша, withSystemInstruction + generateContent(["PROMPT:\n{prompt}", "DATA:\n{fileUri}"]), промт хардкод 'Выполни первичный анализ согласно правилам.'. Сметы: withCachedContent(cacheId). Всё через Redis::throttle gemini:generate (60/60с/block30).
E. Парсинг: json_decode(text) ?? [] — слабая обработка; exception → ['success'=>false,'error'] + Log::error. Ретраи только на Job (tries=5, backoff [30,60,300], timeout 180).
F. DTO Spatie ::from(): PrimaryAnalysisResponse{success, message?, data:PrimaryAnalysis{document_type:DocumentType, description?, tags[]}}. Не Estimate → PrimaryIgnored.
G. Токены: usageMetadata → plt_document_analysis_responses (prompt/completion/total_tokens).

## plt_document_analysis_responses
document_analysis_id, parent_id (ДЕРЕВО стадий — на каждую смету/подвал свой запрос), prompt, step, cache_file_id (index), rules_hash, success, message, data json, state json («первичный анализ показал сметы, для каждой — отдельные запросы позиций/подвала»), token-поля.

## Синхронизация PHP ↔ JSON (главный приём)
PromptService::getCleanSchema($filePath): file_get_contents → preg_replace вырезает <?php/namespace/use/declare → «голый» PHP-код класса в system instruction. НЕ Reflection, НЕ JSON Schema. Ответ валидируется тем же Data::from(). Докблоки Schema-классов — инструкции модели.
Атрибут #[Alias(aliases:[...])] (App\Attributes\Alias, PROPERTY|CLASS_CONSTANT) — синонимы заголовков колонок/маркеры enum, попадают в промт как исходник:
- EstimateHeader: pos←№ п/п,№,поз; code←Шифр,Обоснование,Код; name←Наименование работ,Описание; unit←Ед. изм.,Измеритель; quantity←Количество,Объем; price←Стоимость всего,Итого руб
- EstimateResourceType: Material←МАТ,ФССЦ,прайс-листы; Machine←ЭМ,маш.час,ФСЭМ; Labor←ЗП,ОТ,ЗПМ,чел.час; Overhead←НР,СП,Накладные расходы,Прибыль

Сериализуемые классы: DocumentAnalysis/Schema: PrimaryAnalysis, DocumentType, Responses/PrimaryAnalysisResponse. EstimateAnalysis/Schema: EstimateHeader, EstimateHeaderColumn, EstimateItem, EstimateItemResource, EstimateModifierItem, EstimateSection, EstimateSubSection, EstimateFooterItem, EstimatePassport, enums EstimateType/Target/ResourceType, Responses/EstimatePreview(Response), EstimateFetchItems(Response), EstimateFetchInfo(Response).

## Тексты промтов (LLM_Strategy.md §10)
Мастер-промт смет: РОЛЬ «система извлечения данных из строительных смет РФ»; ТОЛЬКО валидный JSON; нормативные базы ГЭСН/ФЕР/ТЕР/ФССЦпг/ТСН/БД; правила: иерархия раздел→подраздел→позиция; разделение материалы/работы/механизмы; коэффициенты (зимнее удорожание, командировочные) отдельным массивом; нечитаемое → null, не придумывать; числа 1234567.89 без пробелов; НДС по умолчанию 20%; тип SECTION|ITEM. Схема вывода: document_type, normative_base, normative_year, contractor, object_name, date, sections[] (materials_cost/works_cost/machinery_cost/children), totals{overhead, estimated_profit, vat_rate, vat_amount, total_with_vat}, coefficients[], parse_confidence, parse_warnings[].
PrimaryAnalysis (экономный): «первые строки/первая страница → ТОЛЬКО JSON: is_construction_estimate, confidence, estimated_total, currency, normative_base, ignore_reason».
Валидационный промт: контрольные суммы (сумма позиций=subtotal ±0.01; total_without_vat=subtotal+overhead+profit; vat_amount=total×rate/100; все ITEM имеют name) → {"valid":true|false, errors[]}.
Доменные enum: EstimateType — estimate(ЛСР), act(КС-2), resource_calculation(Ведомость ресурсов), defect_list(Дефектная ведомость). EstimateTarget — construction/renovation/medical_equipment/engineering. DocumentType — estimate/tech_spec/unknown.

## Легко потерять
- Экономия на первичном анализе — ЗАМЫСЕЛ (первая страница), но в коде шлётся ВЕСЬ fileUri — gap док↔код. Реальная экономия: Excel→компактный landscape-PDF + context cache на детальном.
- Context caching: только для смет; ttl=6h; кэш = systemInstruction + Content::parse("DOC_URI: {uri}"); isCacheValid = fingerprint && rules_hash=sha1(systemInstruction) && TTL; deleteCache() вручную в конце. Поля plt_document_analysis: gemini_cache_id, gemini_rules_hash, gemini_cache_expires_at, file_fingerprint.
- БАГ: generateJson на gemini-2.0-flash, а кэш создаётся на models/gemini-1.5-flash — разные модели.
- EstimateAnalysisProcessor — заглушка: только Step::EstimateFetchItems; preview/fetch_info не вызываются; замысел — чанки по 80 строк, дерево запросов (поле state).
- Лизинг acquireLease TTL 600с + guard по статусу; firstOrCreate (model_type,model_id,source).
- Дедуп: DocumentProcessor::findExistingByFileSignature (имя+размер, скачивание URL во временный файл).
- recordFailure: backoff min(2**n,240) мин, failed_permanently ≥5, Telegram PipelineAlert::failure.
- Прокси обязателен: Guzzle config('sys.proxies.gemini_proxy'), timeout 300с (RU-ограничения).
- file-ID = last(explode('/', name)) — чтобы SDK не дублировал files/ префикс.
- GeminiService — прокси-объект к Gemini\Client через __call; usageFromResponse null-safe.
- Порядок промта детерминирован: БД [global→group→step, priority ASC] → PHP-схемы → Responses последними.

Первоисточники: Old/app/Repositories/Prompt/PromptRuleRepository.php, Old/app/Services/{PromptService,GeminiService}.php, Old/app/Models/Prompt/PromptRule.php, Old/app/Dto/Prompt/Step.php, Old/app/Process/{DocumentAnalysis,EstimateAnalysis/Schema,DocumentPrepare}/**, Old/app/Models/DocumentAnalysis/DocumentAnalysis.php, миграции 2026_02_19_073335, 2026_02_18_095846, 2026_02_18_100418, 2026_06_19_000001, Old/config/gemini.php, Old/docs/03_Dev/LLM_Strategy.md.
