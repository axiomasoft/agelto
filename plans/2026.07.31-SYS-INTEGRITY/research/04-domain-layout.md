# research/04 — Инвентарь слоёв `app/**` и правило доменного размещения

Слой 3 (design). Deliverable item'а **P3.1**. Правило ниже — проектное решение, которое
разворачивают P3.2 (перенос enum'ов), P3.3 (слои класса A, утверждённые владельцем),
P3.4 (машинный гейт) и P3.5 (синхронизация канона).

**Якорь дерева.** Все числа сняты на `aa5e88d` — это ДЕРЕВО ITEM-КОММИТА в части `app/**`:
item P3.1 не меняет ни одного файла приложения (его `Files` — только
`research/04-domain-layout.md`, `artifacts/app-layer-inventory.json`, `artifacts/inventory.sh`),
поэтому `git show --stat <item-коммит> -- app/` пуст, и `aa5e88d` = дерево item-коммита для
всякого числа про `app/**`. Команда приведена рядом с каждым числом.

Стоит на: `findings/P3-layout-facts-2026-08-04.md` (носитель фактуры, дерево `4b628a8`) ·
`findings/repo-recon-2026-07-31.md` §3, §9 (выводы: гипотеза «корень слоя», прецедент формы гейта,
чего в проекте нет) · `brief/00-brief.md` §3, §6 · `docs/03_Dev/adr/0001-prc-megadomain.md` ·
`docs/03_Dev/adr/0006-bounded-contexts-as-folders.md` · skill `laravel-structure` ·
skill `namespace-refactor-safety` · решения D14, D15, D17, D28–D31, D44, D65, D112.

**Сверка с фактурой ре-дизайна.** Восемь чисел `findings/P3-layout-facts-2026-08-04.md` (дерево
`4b628a8`) пересняты на `aa5e88d` СВОИМИ командами и совпали до единицы: 40 · 353 · 9/13 · 7 · 62 ·
13 · 5 · 1. Расхождения нет — между `4b628a8` и `aa5e88d` лежит один коммит правки плана
(`aa5e88d chore(plan): ре-дизайн P3.1 …`), `app/**` он не трогает.

---

## 1. Инвентарь: слой → файлов в корне → подкаталоги → подобласти

Машинный носитель — `artifacts/app-layer-inventory.json`, воспроизводится командой:

```bash
bash plans/2026.07.31-SYS-INTEGRITY/artifacts/inventory.sh          # перезаписать JSON
bash plans/2026.07.31-SYS-INTEGRITY/artifacts/inventory.sh --check  # сверить с деревом
```

Итог на `aa5e88d`: **542** php-файла в `app/`, **20** доменных папок слоёв, **116** подкаталогов
внутри них, **27** каталогов технической оси (вывод `inventory.sh`).

### 1.1 Доменные папки слоёв (`app/<Слой>/<Домен>`)

`root` — php-файлов непосредственно в корне доменной папки; `всего` — рекурсивно.

| Доменная папка слоя | root | всего | Подкаталоги |
|:--|--:|--:|:--|
| `app/Console/Commands/Procurement` | 8 | 27 | `Ai`, `Analyzers` |
| `app/Console/Commands/Reference` | 1 | 1 | — |
| `app/Dto/Procurement` | 7 | 65 | `Ai`, `Analyzers`, `Bench`, `Details`, `Fixture`, `Gold`, `Prepare`, `Proxy` |
| `app/Enums/Llm` | 5 | 5 | — |
| `app/Enums/Organization` | 1 | 1 | — |
| `app/Enums/Procurement` | **40** | 42 | `Analyzers` |
| `app/Exceptions/Procurement` | 15 | 15 | — |
| `app/Filament/Resources/Procurement` | 0 | 85 | `Ai`, `Crawler`, `Platform`, `Procurements` |
| `app/Jobs/Procurement` | 4 | 5 | `Analyzers` |
| `app/Models/Llm` | 4 | 4 | — |
| `app/Models/Organization` | 1 | 1 | — |
| `app/Models/Procurement` | 14 | 44 | `Ai`, `Crawler`, `Document`, `Estimate`, `Platform` |
| `app/Models/Reference` | 4 | 4 | — |
| `app/Repositories/Llm` | 5 | 5 | — |
| `app/Repositories/Organization` | 2 | 2 | — |
| `app/Repositories/Procurement` | 16 | 17 | `Analyzers` |
| `app/Services/Llm` | 13 | 50 | `Contracts`, `Dto`, `Providers`, `Schema`, `Validation` |
| `app/Services/Organization` | 1 | 1 | — |
| `app/Services/Procurement` | 11 | 121 | `Ai`, `Analyzers`, `Bench`, `Contracts`, `Detail`, `Eis`, `Exceptions`, `Extraction`, `Fixture`, `Gold`, `Modes`, `Prepare`, `Proxy`, `Screening`, `Tuning` |
| `app/Services/Reference` | 2 | 2 | — |

Диапазон «файлов в корне при наличии подкаталогов» — **7–40** (`Dto/Procurement` … `Enums/Procurement`),
то есть шире, чем 7–16 из `findings/P3-layout-facts-2026-08-04.md` §8: та таблица считала только
семь слоёв Procurement, здесь — все двадцать доменных папок.

### 1.2 Подобласти, фактически объявленные подкаталогами

Словарь снят машинно (`subarea_vocabulary` в JSON) — имя подкаталога → в скольких слоях домена
он встречается:

- **`Procurement`:** `Ai` (5 слоёв) · `Analyzers` (6) · `Crawler` (2) · `Platform` (2) · `Proxy` (2) ·
  `Bench` (2) · `Fixture` (2) · `Gold` (2) · `Prepare` (2) · `Document` (1) · `Estimate` (1) ·
  `Detail` (1) · `Details` (1) · `Eis` (1) · `Extraction` (1) · `Modes` (1) · `Screening` (1) ·
  `Tuning` (1) · `Procurements` (1) · `Contracts` (1) · `Exceptions` (1).
- **`Llm`:** `Contracts` · `Dto` · `Providers` · `Schema` · `Validation` — по одному слою каждый.
- **`Reference`, `Organization`:** подкаталогов нет.

Из этого списка `Contracts`/`Dto`/`Exceptions`/`Providers`/`Schema`/`Validation` — **не подобласти**,
а технические подкаталоги слоя (см. §2, инвариант I2 и его оговорка); `Procurements` — ядровая
подобласть Procurement, получившая папку в слое, где группировка обязательна (Filament).

### 1.3 Три наблюдения, из которых выводится правило

1. **Слой, разложенный по подобластям, всё равно держит файлы в корне** — и держит их
   НЕ случайно: 14 файлов в корне `app/Models/Procurement` — это `Procurement`, `Lot`,
   `PurchaseItem`, `Bid`, `Award`, `Protocol`, `Cancellation`, `Clarification`, `Financing`,
   `ProcurementParty`, `ProcurementEvent`, `ProcurementRevision`, `EisDetails`, `OperationLog`,
   то есть **сама закупка со спутниками** (13) плюс один кросс-подобластный журнал операций
   (`find app/Models/Procurement -maxdepth 1 -type f -name '*.php'`). Ни один файл из подобластей
   `Ai`/`Crawler`/`Document`/`Estimate`/`Platform` в корне не лежит. Это самый осознанный слой
   проекта, и он даёт форму правила.
2. **Тот же корень в других слоях заполнен иначе.** В корне `app/Dto/Procurement` — 4 файла
   кроулинга (`FetchResult`, `ListingBatch`, `ListingCursor`, `WindowFilter`) при отсутствии
   подкаталога `Crawler/`; в корне `app/Services/Procurement` — 6 кроулинга и 2 документа
   (`ArchiveWindowScanner`, `CrawlerRunManager`, `CsvBlockIngestor`, `DetailRefetchSelector`,
   `PoliteThrottle`, `SourceMethodFactory`; `DocumentDownloader`, `DocumentIndexer`) при наличии
   пятнадцати других подкаталогов. То есть файл ложится в подкаталог не потому, что так велит
   правило, а потому, что подкаталог для его подобласти УЖЕ существует. Это не правило, а его
   отсутствие — и ровно оно объясняет дрейф: `app/Repositories/Procurement` за четыре дня сам
   обзавёлся подкаталогом `Analyzers/` (`findings/P3-layout-facts-2026-08-04.md` §1, §8).
3. **Ядровая подобласть иногда получает папку.** `app/Filament/Resources/Procurement` держит
   `Procurements/` наравне с `Ai/`, `Crawler/`, `Platform/`, а в корне — ноль файлов. Это не
   противоречит п.1: в слое Filament действует обязательный дополнительный уровень группировки
   `<Домен>/<Подобласть>/<Ресурсы>/` (канон skill `laravel-structure`:
   `Filament/Resources/<Domain>/<Models>/`), поэтому папка ядра там неизбежна. Правило обязано
   допускать оба варианта, а не объявлять один из двух живых слоёв нарушением.

---

## 2. Правило размещения

### 2.1 Формулировка

> **Каждый файл доменной папки слоя принадлежит ровно одной подобласти своего домена, и подобласть
> выражается подкаталогом.** Уровень домена (`app/<Слой>/<Домен>/`) обязателен для всего бизнес-кода;
> четыре домена закрыты ADR-0001 и D22 (`Procurement`, `Reference`, `Organization`, `Llm`), новых не
> вводится. Внутри доменной папки подкаталог подобласти заводится ВМЕСТЕ С ПЕРВЫМ её файлом, а не по
> достижении какого-либо числа файлов; пустых папок «на вырост» не бывает. В корне доменной папки
> легально лежат только два вида файлов: (а) файлы **ядровой подобласти** домена — для `Procurement`
> это сама закупка и её спутники, для `Llm` — вызов LLM и его маршрут, для `Reference` — справочник,
> для `Organization` — организация; (б) **кросс-подобластные** файлы, которые принадлежат всем
> подобластям сразу и ни одной в отдельности (журнал операций, диспетчер конвейера). Ядровой
> подобласти подкаталог не запрещён, а лишь не обязателен: слой, где действует собственный
> обязательный уровень группировки (Filament — `<Ресурсы>/`), заводит папку и ей. Файл
> не-ядровой и не кросс-подобластной природы в корне доменной папки — нарушение независимо от
> размера слоя.

Подобласть файла определяется **носителем**, а не потребителем: enum кладётся туда, где лежит
модель/таблица, состояние которой он описывает; DTO — туда, где живёт стадия или сущность, чью
структуру он несёт; исключение — туда, где лежит бросающий его контур. Правило зеркала слоёв
(skill `laravel-structure`: `Models/X` ↔ `Enums/X` ↔ `Repositories/X`) — прямое следствие: у одной
подобласти один адрес во всех слоях.

**Инвариант I2 (имя подобласти).** Одна подобласть — одно имя во всех слоях. Технические
подкаталоги слоя (`Contracts/`, `Dto/`, `Exceptions/`, `Providers/`, `Schema/`, `Validation/`) и
типовой каталог анализатора (`Analyzers/<Тип>/` — сейм анализатора, канон `CLAUDE.md` и
`.ai/guidelines/glossary.md`) подобластями НЕ являются: они не отменяют обязанности файла
принадлежать подобласти и не судятся инвариантом I2.

### 2.2 Формальная проверяемая форма (что именно сможет проверить Finder)

Правило распадается на три машинных инварианта; первые два проверяются на всём периметре,
третий — только на **выровненных** слоях (D65: слой вне периметра гейт не судит):

- **I1 — доменный уровень обязателен.** Для всякого php-файла бизнес-слоя (`Enums`, `Dto`, `Models`,
  `Repositories`, `Services`, `Exceptions`, `Jobs`, `Console/Commands`, `Filament/Resources`) путь
  имеет вид `app/<Слой>/<Домен>/…`, где `<Домен>` ∈ {`Procurement`, `Reference`, `Organization`,
  `Llm`}. Техническая ось (`Attributes/`, `Casts/`, `Concerns/`, `Guards/`, `Http/`, `Notifications/`,
  `Providers/`, `Pulse/`, `Scopes/`, `Support/`, `Services/Storage/`) от I1 освобождена и обязана НЕ
  нести доменных имён.
- **I2 — namespace ↔ путь и словарь имён.** `namespace` в файле совпадает с его каталогом (PSR-4);
  имя подкаталога подобласти принадлежит словарю подобластей своего домена. I2 — то, что ловит
  недоделанный перенос: файл переехал, `namespace` остался.
- **I3 — корень доменной папки.** В корне выровненного слоя лежат только файлы из ЯВНОГО реестра
  root-legal классов этого слоя (ядро + кросс). Реестр — часть теста; периметр файлов собирается
  ДИНАМИЧЕСКИ (`Symfony\Component\Finder`), поэтому новый файл в корне краснеет по умолчанию, пока
  его туда сознательно не внесли. Это fail-closed форма: рукописный перечень стоит на стороне
  РАЗРЕШЁННОГО, а не на стороне проверяемого (§9.6 разведки: рукописный перечень проверяемых молча
  перестаёт ловить новый файл).

### 2.3 Граница относительно ADR-0006 и ADR-0001

ADR-0006 решает ДРУГОЙ вопрос и правилу не противоречит. Он отклонил ренейм домена в пять bounded
contexts (`SourceIntake`/`NoticeEnrichment`/`DocumentLifecycle`/`EstimateIntelligence`/
`AiOrchestration`) и постановил, что подомены выражаются **структурой каталогов** внутри плоского
`prc_`-мегадомена (ADR-0001). Правило §2.1 — исполнение этого решения, а не его пересмотр: корень
namespace `App\Enums\Procurement` НЕ меняется, ни один домен не переименовывается, ни один новый
домен не вводится; меняется только глубина внутри неизменного корня.

Отсюда — граница для оговорки ADR-0006 «strangler, никогда big-bang». Она адресована **отклонённому**
ренейму контекстов, у которого была бы своя цена: Eloquent morph maps, policy mapping, Filament
Resource discovery, route-model binding, factories. Для выравнивания подкаталогов слоя enum'ов ни
один из этих механизмов не задействован, и это проверено, а не предположено:

- morph maps в проекте нет вовсе — `grep -rn "morphMap\|enforceMorphMap" app/` → пусто;
- policy mapping идёт от `App\Models\**` (строка `az-guard.models_namespace`), слоя enum'ов не
  касается;
- Filament Resource discovery ходит по `app_path('Filament/Resources')`
  (`app/Providers/Filament/AdminPanelProvider.php:41`), слоя enum'ов не касается;
- route-model binding и factories адресуют модели, не enum'ы;
- строковых `class_string` на enum'ы вне комментариев нет (§5).

Поэтому D31 (один item, один коммит, `class_alias`-шимы запрещены) ADR-0006 не противоречит:
трёхфазная схема skill `namespace-refactor-safety` окупается там, где у FQN есть потребители,
которых нельзя обновить одним коммитом, а здесь все 353 файла-потребителя лежат в этом же
репозитории и правятся тем же коммитом. Шим же пережил бы фазу и сделал бы гейт P3.4 ложно-зелёным:
старый путь остался бы валидным. **Исполнителю P3.2: конфликта канона здесь нет, эскалация
«rule conflict» на паре ADR-0006 ↔ D31 не обоснована.**

### 2.4 Ответ про малые плоские слои (три контрольных)

Плоский слой легален ⟺ ВСЕ его файлы принадлежат ядровой подобласти домена (или кросс-подобластны).
Число файлов критерием не является ни сверху, ни снизу — эмпирика на `aa5e88d`: `Dto/Procurement/Bench`
и `Services/Procurement/Ai` содержат по 1 файлу и легальны, а `Exceptions/Procurement` с 15 файлами
в корне — нарушение, потому что его файлы принадлежат четырём разным подобластям.

| Контрольный слой | Файлов | Вердикт правила | Почему |
|:--|--:|:--|:--|
| `app/Enums/Llm` | 5 | **легален** | `CoreLlmPurpose`, `CorePromptStep`, `DocumentTypeScope`, `LlmPurpose`, `PromptStep` — все описывают назначение вызова и композицию промта, то есть ядро домена `Llm`; второй подобласти в слое нет |
| `app/Enums/Organization` | 1 | **легален** | `ProcurementPartyRole` — роль стороны, ядро домена `Organization` |
| `app/Models/Reference` | 4 | **легален** | `Kbk`, `Ktru`, `Okpd2`, `Okved2` — четыре справочника, одна ядровая подобласть домена `Reference` |

Подкаталог в этих слоях заводится в тот момент, когда появится файл ВТОРОЙ подобласти, — не раньше
и не по счётчику. Гейт P3.4 их не краснит: I3 применяется к выровненным слоям, а I1/I2 они не
нарушают.

### 2.5 Правило для файлов, которые заведут идущие после P3 фазы (D30/D44/D65)

Любой класс, создаваемый после закрытия P3.2/P3.3 в слое, вошедшем в выровненный периметр, кладётся
по §2.1: сначала подобласть, потом слой. Корень доменной папки для нового файла закрыт, пока файл не
внесён в реестр I3 сознательным решением. Enum'ы P5 (тип нормы, источник цены ресурса, признак НДС —
если корпус их подтвердит) ложатся в `app/Enums/Procurement/Estimate/`; DTO кроулера P4.3 —
в `app/Dto/Procurement/Crawler/` (папку заводит первый же файл); сервисы хранилища P6.3 остаются на
технической оси `app/Services/Storage/` и I1/I3 не судятся.

---

## 3. Таблица адресов: все 40 enum'ов корня `app/Enums/Procurement`

Перечень снят машинно на дереве item-коммита:

```bash
find app/Enums/Procurement -maxdepth 1 -type f -name '*.php' | wc -l   # → 40 (на aa5e88d)
```

Строк в таблице — **40**, строк «не определено» — 0. Целевой адрес выведен по §2.1 (подобласть
определяется носителем); столбец «Носитель» называет факт, по которому подобласть присвоена.

| # | Файл | Текущий адрес | Целевой адрес | Подобласть | Носитель (чем подтверждено) |
|--:|:--|:--|:--|:--|:--|
| 1 | `AwardStatus.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\` (корень) | ядро | `Models/Procurement/Award.php` — корень слоя моделей |
| 2 | `BidStatus.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\` (корень) | ядро | `Models/Procurement/Bid.php` — корень слоя моделей |
| 3 | `EisProcedureType.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\` (корень) | ядро | колонка `prc_procurements.procedure_type` (`2026_07_04_100011:30`), модель `Procurement.php` — корень |
| 4 | `ProcurementEventType.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\` (корень) | ядро | `Models/Procurement/ProcurementEvent.php` — корень |
| 5 | `ProcurementStatus.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\` (корень) | ядро | `Models/Procurement/Procurement.php` — корень |
| 6 | `ProtocolType.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\` (корень) | ядро | `Models/Procurement/Protocol.php` — корень |
| 7 | `OperationLogLevel.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\` (корень) | кросс | `Models/Procurement/OperationLog.php` — корень; журнал пишут все подобласти |
| 8 | `CrawlerMode.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Crawler\` | Crawler | `Models/Procurement/Crawler/Crawler.php` — колонка `mode` |
| 9 | `RunStatus.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Crawler\` | Crawler | `Models/Procurement/Crawler/Run.php` — статус прогона краулера |
| 10 | `SourceMethod.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Crawler\` | Crawler | колонка `prc_crawlers.source_method` (`2026_07_04_100038:24`) |
| 11 | `ProxyScope.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Proxy\` | Proxy | `Dto/Procurement/Proxy/**` + `Services/Procurement/Proxy/**`; ИСКЛЮЧЕНИЕ-1 (§3.1) |
| 12 | `ProxyFailureReason.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Proxy\` | Proxy | там же; ИСКЛЮЧЕНИЕ-1 (§3.1) |
| 13 | `DocumentStatus.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Document\` | Document | `Models/Procurement/Document/Document.php` — колонка `status` |
| 14 | `RepresentationKind.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Document\` | Document | `prc_document_representations.kind` (`2026_07_27_100055:30`) |
| 15 | `UnprocessableReason.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Document\` | Document | причина терминального `DocumentStatus::Unprocessable` — тот же носитель |
| 16 | `PipelineStage.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Document\` | Document | стадия конвейера ДОКУМЕНТА (`prepare`/`screening`/`extraction`), потребитель — `PipelineRetryFailed` |
| 17 | `AiDocumentType.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Ai\` | Ai | колонки `prc_ai_analyses.document_type` (`2026_07_04_100025:33`) и `llm_routes.document_type` (`2026_07_04_100028:21`) — вердикт скрининга, не атрибут документа |
| 18 | `AnalysisStatus.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Ai\` | Ai | `Models/Procurement/Ai/Analysis.php` |
| 19 | `AnalysisStep.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Ai\` | Ai | `Models/Procurement/Ai/Analysis.php` — колонка `step` |
| 20 | `AnalysisRunStatus.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Ai\` | Ai | `Models/Procurement/Ai/AnalysisRun.php` |
| 21 | `RunMode.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Ai\` | Ai | `prc_ai_analysis_runs.mode` (`2026_07_29_100059:28`) — режим прогона АНАЛИЗА, не краулера |
| 22 | `CorrectionReason.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Ai\` | Ai | `Models/Procurement/Ai/Correction.php` |
| 23 | `ReviewStatus.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Ai\` | Ai | операторская приёмка результата анализа — `Models/Procurement/Ai/Analysis.php` |
| 24 | `FixtureClass.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Ai\` | Ai | `Models/Procurement/Ai/Fixture.php`, таблица `prc_ai_fixtures` |
| 25 | `GoldSource.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Ai\` | Ai | `Fixture::gold_path` — тот же носитель |
| 26 | `PromptSuggestionKind.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Ai\` | Ai | `Models/Procurement/Ai/PromptSuggestion.php` |
| 27 | `PromptSuggestionStatus.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Ai\` | Ai | `prc_ai_prompt_suggestions.status` |
| 28 | `CalcMethod.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Estimate\` | Estimate | `prc_estimates.calc_method` |
| 29 | `EstimateType.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Estimate\` | Estimate | поле `type` паспорта сметы, `Models/Procurement/Estimate/Estimate.php` |
| 30 | `EstimateTarget.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Estimate\` | Estimate | поле `target` паспорта сметы |
| 31 | `EstimateSourceKind.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Estimate\` | Estimate | `Models/Procurement/Estimate/Estimate.php` — адрес сметы в документе |
| 32 | `EstimateValidationStatus.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Estimate\` | Estimate | `prc_estimates.validation_status` |
| 33 | `ExtractionRoute.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Estimate\` | Estimate | путь извлечения СМЕТЫ, потребители — `Services/Procurement/{Extraction,Analyzers/Estimate}` |
| 34 | `DiscoveryMarker.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Estimate\` | Estimate | маркер границы сметы в discovery-проходе, `Dto/Procurement/Analyzers/Estimate/*` |
| 35 | `RowType.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Estimate\` | Estimate | `Models/Procurement/Estimate/Item.php` — тип строки сметы |
| 36 | `ResourceType.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Estimate\` | Estimate | `Models/Procurement/Estimate/Resource.php`, `prc_estimate_resources` |
| 37 | `ModifierKind.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Estimate\` | Estimate | `attributes.modifiers[].kind` позиции сметы |
| 38 | `TotalKind.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Estimate\` | Estimate | `prc_estimate_totals.kind`, `Models/Procurement/Estimate/Total.php` |
| 39 | `IncompleteReason.php` | `App\Enums\Procurement\` | `App\Enums\Procurement\Estimate\` | Estimate | буфер `incomplete_items` извлечения сметы |
| 40 | `FinishReason.php` | `App\Enums\Procurement\` | `App\Enums\Llm\` — **под вердикт владельца** | домен `Llm` | 6 из 7 потребителей — `app/Services/Llm/**`; ИСКЛЮЧЕНИЕ-2 (§3.2) |

Сводка целевых адресов: корень — **7** (6 ядро + 1 кросс) · `Ai/` — **11** · `Estimate/` — **12** ·
`Document/` — **4** · `Crawler/` — **3** · `Proxy/` — **2** · `App\Enums\Llm\` — **1**. Итого 40.

Существующий подкаталог `App\Enums\Procurement\Analyzers\Estimate\` (`EstimateLlmPurpose`,
`EstimatePromptStep`) правилом **не затрагивается** и с новым `Estimate/` не сливается: `Analyzers/<Тип>/` —
ось типа документа (сейм анализатора), закреплённая каноном `CLAUDE.md`/`.ai/guidelines/glossary.md`,
а `Estimate/` — подобласть предметной модели сметы. Исполнителю P3.2: это два разных адреса, слияние
их — материальное отклонение.

### 3.1 ИСКЛЮЧЕНИЕ-1 — `Proxy/` против `Models/Procurement/Crawler/`

Правило §2.1 требует одного адреса подобласти во всех слоях, а фактура даёт два: модели прокси лежат
в `app/Models/Procurement/Crawler/` (`Proxy.php`, `ProxyCheck.php`, `ProxyTarget.php`), тогда как
`app/Dto/Procurement/Proxy/` и `app/Services/Procurement/Proxy/` объявляют `Proxy` самостоятельной
подобластью. Это **противоречие двух слоёв** — по Implementation Rule 1 оно фиксируется как находка,
а не разрешается вкусом.

Для слоя enum'ов адрес выбран `Proxy/` по факту, который снимает симметрию: `ProxyScope` несёт кейс
`Llm` (`app/Enums/Procurement/ProxyScope.php`, `config/crawler.php`), то есть прокси обслуживает не
только кроулинг, и подчинять его подобласти `Crawler` было бы неверно по существу, а не по вкусу.
Выравнивание `Models/Procurement/Crawler/Proxy*.php` → `Models/Procurement/Proxy/` — **класс B**
(слой моделей, §4) и предмет решения владельца (§7), в P3.2 не входит.

### 3.2 ИСКЛЮЧЕНИЕ-2 — `FinishReason` пересекает границу ДОМЕНА

`FinishReason` описывает причину остановки генерации LLM и является обязательным полем
`LlmResponse`/`LlmStructuredResponse`. Потребители (`grep -rlE 'Enums\\+Procurement\\+FinishReason[^A-Za-z]' app`):
`Services/Llm/Dto/LlmResponse.php`, `Services/Llm/Dto/LlmStructuredResponse.php`,
`Services/Llm/Providers/{PrismGeminiProvider,OpenAiCompatibleProvider}.php`,
`Services/Llm/Validation/{ReaskPolicy,StructuredResponseValidator}.php` — шесть в домене `Llm`, и один
в `Procurement`: `Services/Procurement/Analyzers/Estimate/RowRangeChunkSource.php` (читает поле
транспортного DTO — штатное потребление, не владение).

По §2.1 адрес — `App\Enums\Llm\FinishReason`. Это перенос между ДОМЕНАМИ, а не выравнивание
подобласти внутри домена, поэтому D28 отдавал решение владельцу.

> **Вердикт владельца 2026-08-04 — D113: переносить.** Исключение снято: `FinishReason` переносит
> **P3.2** наравне с остальными 39 файлами по таблице §3, временного исключения в реестре I3 гейта
> P3.4 не заводится. Абзац выше сохранён как провенанс постановки.

---

## 4. Прочие нарушения правила: слои с классом A/B

Класс A присваивается ТОЛЬКО при выполнении всех трёх условий (Implementation Rule 4): нет
биндинга/резолва по FQN-**строке**; нет ссылок вне `use`-импортов и текста доков; нет discovery по
пути каталога. Сомнение → B.

Проверки, общие для всех строк (на `aa5e88d`):

```bash
grep -rn "morphMap\|enforceMorphMap" app/                         # → пусто (morph maps нет)
grep -rnE "'App\\\\\\\\[A-Za-z]" app | grep -vE ':[0-9]+:use '     # → 1 (Guards/Admin, техническая ось)
grep -rn "discover\|app_path(" app/Providers bootstrap/app.php     # → discoverResources/Pages/Widgets, Filament
grep -oE "path: .*" phpstan-baseline.neon | ...                    # → распределение записей по слоям
```

| Слой | Нарушение правила | FQN-строка | Ссылки вне `use` | Discovery по пути | Класс | Обоснование класса |
|:--|:--|:--|:--|:--|:--:|:--|
| `app/Enums/Procurement` | 33 из 40 файлов корня — не ядро и не кросс | нет | 7 комментариев в миграциях (§5) | нет | **A** | в scope безусловно (D28); baseline записей на `app/Enums/**` нет (`grep -n "Enums" phpstan-baseline.neon` → пусто) |
| `app/Exceptions/Procurement` | 15 файлов корня, 0 подкаталогов, четыре группы (`Ai` 9, `Proxy` 2, домен `Llm` 2, кросс 2) | нет | нет | нет | **A** | чистый `use`-периметр, 0 записей baseline, ничего не резолвится по имени |
| `app/Dto/Procurement` | 4 из 7 файлов корня — подобласть `Crawler`, папки для неё нет | нет | нет | нет | **A** | `#[TypeScript]`/`config/typescript-transformer.php` в проекте нет (§9.5 разведки), 0 записей baseline |
| `app/Repositories/Procurement` | 13 из 16 файлов корня — `Ai` (10), `Document` (2), `Proxy` (1) | нет | 3 записи baseline с `path:` | нет | **A** | инъекция конструктором по `::class`; перенос требует правки трёх `path:` в baseline — механика, не риск |
| `app/Services/Procurement` | 8 из 11 файлов корня — `Crawler` (6) и `Document` (2) | нет (`config('crawler.source_methods')` хранит `::class`, `SourceMethodFactory:tryMake`) | 34 записи baseline с `path:` | нет | **A** | формально критерии выполнены, но слой — 121 файл и боевой контур P4/P5/P6 (D15 coupling surface); цена названа в §7 |
| `app/Services/Llm` | 13 файлов корня несут ≥3 подобласти (транспорт, промты, биллинг); подкаталоги слоя — технические, не подобласти | нет | 6 записей baseline | нет | **A** | критерии выполнены; но домен `Llm` заморожен контрактом фазы P2 (§7 — не предлагается) |
| `app/Console/Commands/Procurement` | 5 из 8 файлов корня — `Crawler` (4) и `Document` (1), при живых `Ai/`, `Analyzers/`; три `Pipeline*` кросс-подобластны и легальны | нет | 5 записей baseline | **ДА** — команды подхватываются по каталогу `app/Console/Commands` | **B** | discovery по пути присутствует буквально; она рекурсивна и уже работает на вложенности `Procurement/Ai/**`, но правило 4 требует fail-safe |
| `app/Filament/Resources/Procurement` | нарушений нет: корень пуст, все 4 подобласти в папках | нет | 1 запись baseline | **ДА** — `discoverResources(in: app_path('Filament/Resources'))` | **B** | переносить нечего; класс проставлен для полноты периметра гейта |
| `app/Models/Procurement` | корень чист (ядро + `OperationLog`); нарушение одно — `Proxy*` внутри `Crawler/` (§3.1) | **ДА** — `az-guard.models_namespace = 'App\\Models\\'` → `PolicyDiscovery` резолвит политику от FQN модели строкой | нет | нет | **B** | строковый резолв политик — ровно запрещающее условие правила 4 |
| `app/Jobs/Procurement` | 4 файла корня: `PipelineJob` (кросс), `PrepareDocumentJob` (`Document`), `ScreeningJob`/`ShadowAnalysisJob` (`Ai`) | нет | нет | нет | **B** | FQN job'а сериализуется в payload очереди и в `failed_jobs`: перенос делает нечитаемым всё, что уже лежит в Redis/таблице. Боевого прогона не было, но условие рантаймовое, а не статическое |
| `app/Attributes/Ai` | нарушает I1: `Ai` — подобласть, а не домен; адрес по канону — `Attributes/<Domain>/` или `Attributes/Common/` | нет | нет | нет (читается рефлексией по классу атрибута) | **A** | 2 файла, потребители — `use`-импорты; но предмет — контракт ответа AI (P2.3), поэтому в P3.3 не предлагается (§7) |
| `app/Models/User.php`, `app/Console/Commands/MaintainPartitions.php` | лежат в корне СЛОЯ без доменной папки (нарушение I1) | `User` — да (`config/auth.php`, `az-guard.models_namespace`) | нет | нет | **B** | канон фреймворка; трогать без нужды дороже, чем оставить. Кандидаты на явное исключение в реестре I1 |

### 4.1 Остальные десять доменных папок слоёв: нарушений нет, класс проставлен для полноты

Инвентарь §1.1 несёт 20 доменных папок; в таблице выше разобраны десять — все, где есть что
переносить. Оставшиеся десять правило §2.1 объявляет легальными (все их файлы — ядровая подобласть
своего домена, §2.4), но класс переносимости проставляется и им: он понадобится, когда в слое
появится файл второй подобласти.

| Слой | Файлов | Вердикт правила | Класс | Обоснование класса |
|:--|--:|:--|:--:|:--|
| `app/Enums/Llm` | 5 | легален (§2.4) | **A** | нет строкового резолва, нет discovery, 0 записей baseline |
| `app/Enums/Organization` | 1 | легален (§2.4) | **A** | то же |
| `app/Models/Llm` | 4 | легален | **B** | `az-guard.models_namespace = 'App\\Models\\'` — строковый резолв политик, как у `Models/Procurement` |
| `app/Models/Organization` | 1 | легален | **B** | то же (+3 записи baseline с `path:`) |
| `app/Models/Reference` | 4 | легален (§2.4) | **B** | то же |
| `app/Repositories/Llm` | 5 | легален | **A** | инъекция по `::class`, 0 записей baseline |
| `app/Repositories/Organization` | 2 | легален | **A** | то же (1 запись baseline) |
| `app/Services/Organization` | 1 | легален | **A** | нет резолва по строке |
| `app/Services/Reference` | 2 | легален | **A** | то же |
| `app/Console/Commands/Reference` | 1 | легален | **B** | discovery по каталогу `app/Console/Commands` — то же основание, что у `Console/Commands/Procurement` |

Технические каталоги внутри доменных папок (`Services/Procurement/{Contracts,Exceptions}`,
`Services/Llm/{Contracts,Dto,Providers,Schema,Validation}`) правилом объявлены легальными (§2.1,
оговорка I2), но порождают отдельную находку: **исключения домена живут по двум адресам** —
`app/Exceptions/Procurement/**` (15 файлов) и `app/Services/Procurement/Exceptions/**` (2 файла:
`CardUrlTemplateMissingException`, `SourceMethodUnavailableException`). Правило §2.1 этого не решает
(оно про подобласти, не про выбор слоя) — вопрос уходит владельцу (§7).

Вторая находка того же рода: **`Detail/` (Services) и `Details/` (Dto) — одна подобласть под двумя
именами**, нарушение I2. Выравнивание имени — часть переноса соответствующего слоя, если он войдёт
в P3.3.

---

## 5. Риски переноса: где путь класса встречается НЕ `use`-импортом

Полнота доказывается шаблоном `-E 'App\\+Enums\\+Procurement'` (Implementation Rule 8: `.` в шаблоне
`App.Enums.Procurement` матчит ровно один символ и экранированную форму `App\\Enums\\…` не видит —
из-за этого §9.3 разведки насчитала одно вхождение вместо семи):

```bash
grep -rnE 'App\\+Enums\\+Procurement' database config resources | grep -vE ':[0-9]+:use '   # → 7 строк (на aa5e88d)
```

| # | Файл:строка | Форма | Класс | Что это |
|--:|:--|:--:|:--|:--|
| 1 | `database/migrations/2026_07_04_100036_create_prc_estimate_resources_table.php:23` | `\` | `ResourceType` | комментарий кода (не `comment()` колонки) |
| 2 | `database/migrations/2026_07_27_100055_create_prc_document_representations_table.php:30` | `\` | `RepresentationKind` | текст `comment()` колонки `kind` |
| 3 | `database/migrations/2026_07_29_100059_create_prc_ai_analysis_runs_table.php:28` | `\\` | `RunMode` | текст `comment()` колонки `mode` |
| 4 | `database/migrations/2026_07_29_100059_create_prc_ai_analysis_runs_table.php:29` | `\\` | `AnalysisRunStatus` | текст `comment()` колонки `status` |
| 5 | `database/migrations/2026_07_31_100061_add_routing_columns_to_prc_proxies_table.php:23` | `\` | `ProxyScope` | текст `comment()` колонки `scopes` |
| 6 | `database/migrations/2026_07_31_100061_add_routing_columns_to_prc_proxies_table.php:26` | `\` | `ProxyFailureReason` | текст `comment()` колонки `last_failure_reason` |
| 7 | `database/migrations/2026_07_31_100062_create_prc_proxy_targets_table.php:34` | `\` | `ProxyFailureReason` | текст `comment()` колонки `last_failure_reason` |

Ни одно из семи вхождений не резолвится кодом — все они текст. Но после переноса каждое становится
ложным указателем, поэтому правятся вместе с `use` (D29 разрешил ровно это и ничего больше).
Внимание исполнителю P3.2: строки 3–4 экранированы (`App\\Enums\\…`) и обычной заменой
`App\Enums\Procurement\` не находятся.

Остальная поверхность переноса — механическая:

```bash
grep -rlE 'App\\+Enums\\+Procurement' app tests database config resources docs .ai .claude CLAUDE.md | wc -l   # → 353
grep -rlE '^use App\\+Enums\\+Procurement' database/migrations | wc -l   # →  9 (падают на class not found)
grep -rlE  'App\\+Enums\\+Procurement'     database/migrations | wc -l   # → 13 (use ИЛИ комментарий)
grep -c "message:" phpstan-baseline.neon                                  # → 62 (записей на app/Enums/** нет)
grep -rlnE 'App\\+Enums\\+Procurement|app/Enums/Procurement' docs .ai .claude CLAUDE.md | wc -l   # → 13
```

Разбивка 353 по каталогам (`grep -rlE … <каталог> | wc -l`): `app` 199 · `tests` 105 ·
`database/factories` 23 · `database/migrations` 13 · `database/seeders` 2 · `config` 2 · `docs` 8 ·
`.ai` 1 · `.claude`/`resources`/`CLAUDE.md` 0.

Все пять чисел совпали с `findings/P3-layout-facts-2026-08-04.md` (дерево `4b628a8`) — дрейфа между
ре-дизайном и исполнением нет.

---

## 6. Проект гейта для P3.4

**Форма — Finder-тест, не `pest-plugin-arch`.** Предмет проверки — файловая система и соответствие
«namespace ↔ путь», а не граф `uses` (§9.6 разведки). Файл: `tests/Unit/Architecture/DomainLayoutTest.php`
(рядом с прецедентом `CoreKnowsNoDocumentTypeTest.php`), тестов — три, по одному на инвариант.

| Тест | Инвариант | Как собирается периметр | Что делает красным |
|:--|:--|:--|:--|
| «бизнес-слой не держит файлов без доменной папки» | I1 | `Finder` по `app/<Слой>` для закрытого списка бизнес-слоёв; техническая ось — отдельный закрытый список исключений | php-файл в `app/<Слой>/` или в `app/<Слой>/<НеДомен>/` |
| «namespace совпадает с путём, имя подобласти — из словаря» | I2 | `Finder` по всем `app/**/*.php`; namespace читается из файла | несовпадение PSR-4 либо подкаталог с именем вне словаря домена |
| «в корне выровненного слоя только реестровые классы» | I3 | `Finder` по корню каждого выровненного слоя; реестр root-legal — константа теста | любой не внесённый в реестр файл в корне |

**Обязательная красная проба** (P3.4 её и требует): временный файл-пустышка в корне выровненного
слоя (`app/Enums/Procurement/ZzzProbe.php`) обязан уронить тест I3, а файл с namespace, не равным
каталогу, — тест I2. Проба выполняется и снимается в рамках P3.4; зафиксировать нужно ВЫВОД
красного прогона, а не утверждение «проверено».

**Ложно-зелёные формы, которые запрещены** (доказаны в `plans/archive/2026.07.28-EST-ARCH/findings/
core-arch-gate-2026-07-30.md` и §9.6 разведки):

- `expect([много таргетов])->not->toUse(…)` — множество таргетов работает по «ИЛИ»;
- `->ignoring()` для вычитания — ослепляет проверку;
- рукописный перечень ПРОВЕРЯЕМЫХ файлов — молча перестаёт ловить новый файл. Рукописным может
  быть только перечень РАЗРЕШЁННОГО (реестр I3, список технической оси I1): тогда новый файл
  краснеет по умолчанию.

**Реестр I3 на момент закрытия P3.2** (7 файлов корня из §3 + временное исключение `FinishReason`
до вердикта владельца по §3.2). Слои, не вошедшие в выровненный периметр, тестом I3 не судятся
(D65); I1/I2 применяются ко всему `app/**`, и на `aa5e88d` их нарушают только `app/Models/User.php`,
`app/Console/Commands/MaintainPartitions.php` и `app/Attributes/Ai/**` — все трое обязаны попасть в
список исключений I1 явными строками с причиной, иначе гейт красный с первого прогона.

---

## 7. Гейт владельца: что предлагается в P3.3

Решение об объёме принадлежит владельцу (D28), исполнитель только предлагает. **P3.2 (enum'ы) идёт
безусловно и в гейт не входит.**

> **Вердикт владельца 2026-08-04 — D113.** Утверждены ТРИ слоя из предложенных ниже:
> `app/Exceptions/Procurement`, `app/Dto/Procurement`, `app/Repositories/Procurement`.
> `app/Services/Procurement`, `app/Services/Llm`, `app/Attributes/Ai` в P3.3 НЕ входят;
> класс B остаётся основанием отдельного плана. `FinishReason` — переносить (§3.2).
> Ниже — предложение, как оно выносилось (провенанс).

**Предлагается в P3.3 (класс A, выигрыш выше цены):**

| Слой | Файлов к переносу | Цена | Почему стоит |
|:--|--:|:--|:--|
| `app/Exceptions/Procurement` | 15 (все) | 0 записей baseline, чистый `use`-периметр | самый дешёвый слой: 4 подобласти в одном плоском каталоге, ноль механизмов, завязанных на путь |
| `app/Dto/Procurement` | 4 из 7 (подобласть `Crawler`) | 0 записей baseline | закрывает единственную дыру слоя; заводит `Dto/Procurement/Crawler/`, куда по D65 обязан лечь DTO фильтра P4.3 |
| `app/Repositories/Procurement` | 13 из 16 | 3 записи `path:` в baseline | слой уже начал раскладываться сам (`Analyzers/` появился без правила) — без гейта дрейф продолжится |

**Не предлагается, но класс A — на усмотрение владельца:**

- `app/Services/Procurement` (8 файлов корня из 121 в слое, 34 записи baseline). Формально
  механический, но это боевой контур, который правят P4 (`CsvBlockIngestor`, `Eis/**`), P5 и P6
  (`DocumentDownloader` — D15 coupling surface). Перенос сейчас гарантирует конфликты с ещё не
  исполненными фазами; дешевле после P4/P6 отдельным item'ом.
- `app/Services/Llm` (13 файлов корня). Домен `Llm` только что прошёл фазу P2 с замороженным
  контрактом промтов; перекладывать его через два дня после закрытия — риск без выигрыша.
- `app/Attributes/Ai` (2 файла, нарушение I1). Предмет — контракт ответа AI (P2.3); перенос трогает
  `ContractAttributeReader` и доки P2, закрытые вчера.

**Уходит в `open-questions.md` как основание отдельного плана (класс B):**

- `app/Models/Procurement` — строковый резолв политик (`az-guard.models_namespace`); сюда же
  выравнивание `Crawler/Proxy*.php` → `Proxy/` (ИСКЛЮЧЕНИЕ-1, §3.1).
- `app/Jobs/Procurement` — FQN в payload очереди и `failed_jobs`.
- `app/Console/Commands/Procurement`, `app/Filament/Resources/Procurement` — discovery по пути.
- Два адреса исключений домена (`app/Exceptions/Procurement` vs `app/Services/Procurement/Exceptions`).
- `Detail/` vs `Details/` — одна подобласть под двумя именами (нарушение I2).
- **Вердикт по `FinishReason`** (ИСКЛЮЧЕНИЕ-2, §3.2): переносить ли enum в домен `Llm`.

**Пустой список — легальный исход:** если владелец не утверждает ни одного слоя, P3.3 закрывается
`⛔ Skipped by decision`, а P3.4 судит периметр из одного слоя enum'ов.

**Механика ожидания (Implementation Rule 11).** Item закрывался ПРЕДЛОЖЕНИЕМ: на момент закрытия
ответа не было, в `open-questions.md` заведена OQ-8 со статусом `Decision pending`, P3.3 в
`handoff.md` получил форму `waiting:`. Вердикт пришёл в тот же день и зафиксирован **D113** —
OQ-8 переведена в `Resolved`, P3.3 из формы `waiting:` вышел, батч B2 (`P3.2-P3.3`) исполняется
группой, как предписывает `roadmap.md` §B2.
