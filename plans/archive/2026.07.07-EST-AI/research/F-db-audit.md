# Отчёт F — критический аудит схемы БД v2 (2026-07-07)

Проверено: 47 миграций, 47 моделей, ТЗ docs/specs/v2/01-database.md. Данных нет (миграции 2026-07-04) — правки дёшевы.

## Ось 1. НЕЙМИНГ — БЛОКЕР
ТЗ §0.1 задаёт 9 префиксов (plt_ ref_ org_ prc_ doc_ ai_ est_ crw_ sys_); реализация схлопнула 6 в prc_ (prc_platforms, prc_documents, prc_ai_*, prc_estimate_*, prc_crawler*, prc_operation_logs). Модели жёстко: protected $table='prc_...'.
Двойное отклонение: ТЗ §0.37 запрещает повтор домена в имени («est_items, не est_estimate_items»), факт — prc_estimate_items/sections/resources/totals, prc_crawler_runs, prc_document_versions.
Имена индексов/FK в 6 миграциях с raw-DDL сохранили ТЗ-шные префиксы, не совпадающие с фактическим именем таблицы:
- prc_documents → doc_documents_procurement_external_id_unique (…100018:48)
- prc_ai_llm_calls → ai_llm_calls_analysis_id_index, ai_llm_calls_purpose_called_at_index, FK ai_llm_calls_analysis_id_foreign (…100026:39-42)
- prc_crawler_runs → crw_runs_crawler_id_running_unique (…100039:43)
- prc_fetches → crw_fetches_* + FK (…100041:34-41)
- prc_operation_logs → sys_operation_logs_entity_index (…100046:29)
Мелочи: .claude/rules/migrations.md требует #[Table]-атрибут — по факту protected $table (0 попаданий); модель Ai\AiRoute с дубль-префиксом (остальные в Ai\ без префикса).
Рекомендация: решить таксономию ДО данных: (a) вернуть 9 префиксов ТЗ или (b) закрепить prc_-мегадомен → переименовать индексы/FK в prc_*, обновить ТЗ §0.1. Сейчас migrate:fresh, потом — rewrite партиций.

## Ось 2. СВЯЗИ — СЛАБОСТЬ
- Дыра: prc_procurement_parties.procurement_id БЕЗ FK (…100010:18-19, parties идёт до procurements, FK позже не добавлен). organization_id рядом — cascade.
- onDelete почти везде опущен: явный только в 4 FK (registries/regions→platform, status_mappings→registry, parties→org, все cascade). Иерархия смет (sections/items/resources/totals→estimate) и детальные prc_* (protocols/bids/awards/clarifications/cancellations/financing/items/lots→procurement) — constrained() без onDelete → RESTRICT. Переразбор/GC заблокированы. Нарушение .claude/rules/migrations.md.
- Неэнфорсимые по дизайну (партиционирование, ок): current_revision_id, first_seen_revision_id.
- Уникальные ключи — сильная сторона: (registry_id, source_number); doc_versions(document_id, content_hash)+глоб.индекс; analyses(document_version_id, step); org(inn,kpp) NULLS NOT DISTINCT (…100009:34). pg_partman на 5 таблиц (…100047) idempotent.
- Полиморфизм: только мягкий prc_operation_logs(entity_type, entity_id) без morphMap; prc_events.entity_ref jsonb — достаточно.

## Ось 3. МУЛЬТИПЛОЩАДОЧНОСТЬ — ОК
- Данные-не-код: prc_status_mappings(registry_id, source_status, canonical_status) (…100002); prc_document_types.code открытый codelist (…100003); status/document_type/procedure_type — text.
- Шов: prc_eis_details чистая 1:1-extension (procurement_id PK+FK, …100017) + attributes jsonb на ядре/закупках/лотах/организациях. Образец для будущей <platform>_details.
- Остаточная ЕИС-привязка в ядре (все nullable, ТЗ допускает §11.1): ikz, okpd2_code(s), okved_*, source_guid. prc_items.classifier_scheme (…100015:36) заменяем.
Вердикт: коммерческая площадка — без миграций ядра.

## Ось 4. МУЛЬТИТИП ДОКУМЕНТОВ — СЛАБОСТЬ→БЛОКЕР (на результате)
- Вход/роутинг расширяемы: document_types.has_extractor; ai_analyses.document_type text; ai_routes.document_type с wildcard 'any' и exact-приоритетом (…100028:18,33). Новый тип — строкой, без миграции.
- Узкое место: единственная нормализованная витрина экстракции — prc_estimate* (5 таблиц). Общего «extraction artifact» нет; ai_analyses.result jsonb универсален; prc_estimates.analysis_id UNIQUE (…100033:22) = 1 анализ → 1 смета (NB: конфликт с «несколько смет в одном документе»? — проверить: unique именно analysis_id, а нужен 1:N).
- Рекомендация: до второго типа извлечения — либо generic ai_extractions (document_type + payload jsonb + FK на витрину), либо зафиксировать «нормализуем только сметы, остальное jsonb».

## Ось 5. CRM — ОК/СЛАБОСТЬ
- org_organizations чистый shared-реестр (uuid, inn/kpp/ogrn, attributes) — конфликтов с будущим crm_ нет, аддитивно.
- Слабость 1: нет first-class контактов/персон (контакты в jsonb: parties.attributes, eis_details.contact_person; org phone/email — по одному скаляру). CRM потребует таблицу persons.
- Слабость 2: нет users↔organizations, владельца/tenant — добавляется без помех.

## Ось 6. AI-СЛОЙ — СЛАБОСТЬ (обратная связь оператора)
- Покрыто: providers/routes/prompts/llm_calls (партиц., токены/стоимость/cache_id)/dataset_examples (source llm/manual/corrected, verified_by, verified_at, version)/eval_*.
- ПРОБЕЛ: в prc_ai_analyses нет review_status/reviewed_by/reviewed_at/operator_comment/is_correct — «проверено/что не так» негде хранить на боевом анализе (только в датасете).
- «Повторный прогон» структурно заблокирован: unique(document_version_id, step) не даёт второй анализ; нет parent_analysis_id/attempt/superseded_at — истории перепрогонов нет.
- Мелочь: ai_eval_runs.dataset_version text без FK — приемлемо.

## Топ-5 правок СЕЙЧАС
1. Таксономия префиксов: выбрать (a) 9 префиксов ТЗ или (b) prc_-мегадомен; минимум — переименовать raw-индексы/FK в 6 миграциях (100018/100026/100039/100041/100046) под имя таблицы.
2. Фидбэк оператора + перепрогоны в prc_ai_analyses: review_status, reviewed_by (FK users nullOnDelete), reviewed_at, operator_comment, parent_analysis_id; ослабить unique(document_version_id, step) → partial unique на «активный» анализ.
3. Вернуть FK prc_procurement_parties.procurement_id.
4. Явный onDelete (cascade) иерархии смет и детальным prc_*.
5. Шов результата извлечения под мультитип (generic ai_extractions ЛИБО зафиксировать jsonb-only для не-смет).

## Отложить
Полный ренейм до 9 префиксов (если выбран prc_-мегадомен); контакты-сущность (до CRM); est ltree/тюнинг партиций; витрина prc_search (миграции нет вообще — полнотекст не реализован); морф-хелперы.
