---
audience: dev
type: explanation
status: draft
---

# Архитектура

## Домены

Структура `app/**` плоская, без модулей: четыре домена по короткому префиксу таблицы.

| Домен | Префикс | Что покрывает |
|:--|:--|:--|
| `Procurement` | `prc_*` | Единый домен закупок: заказы, документы, сметы, AI-подомен предметки (анализы/датасет), crawling-инфраструктура, площадки, логи |
| `Reference` | `ref_*` | Общие справочники: ОКПД2, КТРУ, ОКВЭД2, КБК |
| `Organization` | `org_*` | Организации |
| `Llm` | `llm_*` | Провайдер-агностичный LLM-транспорт: провайдеры, маршруты, промты, вызовы (D22, P1.5) |

Таксономия `prc_`-мегадомена — осознанное решение (D11, `plans/EST-AI/plan.md` §5), не 9-префиксная
схема из раннего ТЗ; закреплена ADR [`adr/0001-prc-megadomain.md`](adr/0001-prc-megadomain.md).

## Раскладка `app/**` по слоям

Нет `app/Process/**` (v1-канон корневого слоя не возрождается, D1) — процессная когезия v1
сохраняется на уровне подпапок сервиса, не корневого слоя:

- `Controllers/` — только HTTP: auth, validate, delegate to Action/Service/Job dispatch, response.
- `Repositories/` — read/write доступ к `prc_*`/`org_*`/`llm_*`; `*ReadRepository`/`*StoreRepository`
  (см. skill `repositories`). Персистентность — ТОЛЬКО через них (не сквозной Eloquent из сервисов).
- `Services/Procurement/{Prepare,Screening,Extraction}/` — стадии пайплайна документа/сметы
  (`app/Services/Procurement/`: `PipelineDispatcher`, `DocumentDownloader`, `Prepare/`, `Screening/`,
  `Extraction/`, `Eis/`, `Detail/`).
- `Services/Procurement/Proxy/` — слой маршрутизации исходящего трафика (`ProxyResolver`,
  `ProxyCircuitBreaker`, `ProxyScoreCalculator`) единой точкой выдачи для HTTP-каналов кроулинга и
  LLM; порт `App\Services\Llm\Contracts\OutboundProxy` — узкая граница со стороны домена `Llm`,
  реализация — адаптер `PoolOutboundProxy` со стороны прокси-слоя. Подробнее —
  [`crawling-pipeline.md`](crawling-pipeline.md).
- `Services/Llm/` — транспортный слой домена `Llm`: `LlmRouteResolver`, `PromptComposer`,
  `CorrectionAwareComposer`, `LlmCacheManager`, `LlmCallRecorder`, `ShadowDispatch`, `Providers/`
  (`PrismGeminiProvider`, `OpenAiCompatibleProvider`), `Contracts/` (`LlmProvider` и capability-
  интерфейсы), `Schema/` (генерация словаря синонимов промт-контекста и `response_schema` из
  Data-классов), `Dto/`,
  `Validation/`; модели/фабрики/репозитории транспорта — `app/Models/Llm/**`,
  `database/factories/Llm/**`, `app/Repositories/Llm/**` (D22, P1.5). Подробнее —
  [`llm-providers.md`](llm-providers.md).
- `Services/Storage/` — адресация файлов на дисках (P6): `FileLocationResolver` — единственная
  точка «по записи дай диск+путь» (read-половина фолбэкает `null`/пробует цепочку прошлых
  поколений на окно cutover, D51) и «на какой диск класть новый объект» (write-половина —
  единственный легальный читатель конфиг-ключей диска, `crawler.documents_disk`/
  `ai.fixtures.disk`/`crawler.raw_disk`); `FileLocation` — неизменяемый VO (`disk`+`path`).
  `Console/Commands/Storage/DiskBackfillCommand` (`storage:disk-backfill`) — материализация `null`
  и перенос объектов между дисками с верификацией sha256/размера. Подробнее — колонка `disk` в
  [`database.md`](database.md) и runbook [`storage-provider-switch.md`](runbooks/storage-provider-switch.md).
- `Dto/` / `Enums/` — доменные контракты и enum'ы; размещение внутри доменной папки — по правилу
  подобластей, см. «Правило размещения внутри доменной папки слоя» ниже.
- `Jobs/Procurement/` — очереди (`PipelineJob` — общий абстракт, `PrepareDocumentJob`,
  `ScreeningJob`, `EstimateExtractionJob`).
- `Console/Commands/{Procurement,Ai}/` — artisan-команды пайплайна (`pipeline:*`, `crawler:*`,
  `ai:*`) — шпаргалка [`commands.md`](commands.md).
- `Filament/Resources/Procurement/**` — админ-панель, см. [`admin-panel.md`](admin-panel.md).

### Правило размещения внутри доменной папки слоя

Каждый файл доменной папки слоя (`app/<Слой>/<Домен>/`) принадлежит ровно одной подобласти домена,
и подобласть выражается подкаталогом — он заводится вместе с первым её файлом, не по достижении
какого-либо числа файлов (пустых папок «на вырост» не бывает). В корне доменной папки легально
лежат только: (а) файлы **ядровой подобласти** домена — для `Procurement` это сама закупка и её
спутники, для `Llm` — вызов и его маршрут, для `Reference` — справочник, для `Organization` —
организация; (б) **кросс-подобластные** файлы, принадлежащие всем подобластям сразу и ни одной в
отдельности (журнал операций, диспетчер конвейера). Подобласть файла определяет её **носитель**, не
потребитель: enum кладётся туда, где лежит модель/таблица, чьё состояние он описывает; DTO — туда,
где живёт стадия или сущность, чью структуру он несёт — прямое следствие правила зеркала слоёв
(skill `laravel-structure`: `Models/X` ↔ `Enums/X` ↔ `Repositories/X`, у одной подобласти один адрес
во всех слоях). Технические подкаталоги слоя (`Contracts/`, `Dto/`, `Exceptions/`, `Providers/`,
`Schema/`, `Validation/`) и типовой каталог анализатора (`Analyzers/<Тип>/`) подобластями не
являются и правилу не подчиняются. Плоский слой (без подкаталогов подобластей) легален, если ВСЕ
его файлы принадлежат ядровой подобласти домена или кросс-подобластны — число файлов критерием не
является ни сверху, ни снизу (`app/Enums/Organization` — 1 файл, легален; `app/Enums/Llm` — 5
файлов, легален; но 15 файлов четырёх разных подобластей в одном корне — нарушение).

Правило проверяется машинно (`Symfony\Component\Finder`, не `pest-plugin-arch` — доказанные
ложно-зелёные формы) тестом `tests/Unit/Architecture/DomainLayoutTest.php`: доменный уровень
обязателен для бизнес-слоёв, `namespace` файла совпадает с его путём (PSR-4), а корень
выровненного слоя ограничен явным реестром root-legal классов — новый файл в корне краснеет по
умолчанию, пока его туда сознательно не внесли (fail-closed). Гейт судит только выровненные слои;
слой вне периметра гейтом не проверяется, но правило размещения обязательно для любого нового
класса в нём.

## Слои и транзакции/jobs

Полное изложение — корневой `CLAUDE.md` (Layer Responsibilities, Transaction & Jobs Policy), не
дублируется здесь. Кратко: короткие синхронные мутации — в Action/Service с явной транзакцией;
длительный crawling/parsing/AI — queued Jobs через Horizon, не в HTTP-request; Chromium — только в
worker-контейнере `horizon`; повтор job не должен дублировать Document/Analysis (idempotency-guard
по статусу + lease, см. [`document-pipeline.md`](document-pipeline.md)).

## Архитектурные решения (ADR)

Несущие решения зафиксированы в [`adr/`](adr/); меняем договорённость — обновляем ADR, не код молча.

- [`0001-prc-megadomain.md`](adr/0001-prc-megadomain.md) — таксономия `prc_`-мегадомена.
- [`0002-prism-transport-facade.md`](adr/0002-prism-transport-facade.md) — Prism за фасадом `LlmProvider`.
- [`0003-row-range-plus-windowed-fallback.md`](adr/0003-row-range-plus-windowed-fallback.md) — row-range + windowed fallback.
- [`0004-jsonb-for-non-estimate-results.md`](adr/0004-jsonb-for-non-estimate-results.md) — результат не-смет в jsonb.
- [`0005-protected-prompts.md`](adr/0005-protected-prompts.md) — `is_protected` промты read-only в админке.
- [`0006-bounded-contexts-as-folders.md`](adr/0006-bounded-contexts-as-folders.md) — подомены выражаются папками, namespace-ренейм в bounded contexts отклонён.
- [`0007-ocds-as-exchange-layer.md`](adr/0007-ocds-as-exchange-layer.md) — OCDS как exchange layer, не внутренняя схема.
- [`0008-thin-provenance-slice.md`](adr/0008-thin-provenance-slice.md) — provenance тонким срезом по оспариваемым полям, не 5 уровней.

## Смежное

- [`database.md`](database.md) — схема, FK, unique-инварианты.
- [`crawling-pipeline.md`](crawling-pipeline.md) / [`document-pipeline.md`](document-pipeline.md) /
  [`llm-providers.md`](llm-providers.md) — internals по стадиям.
- Канон имён/глаголов методов — `.ai/guidelines/glossary.md`, `docs/02_Workflow/glossary.md`.
