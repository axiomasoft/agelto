# HANDOFF — 2026-07-30 — after P3.1

**Next:** `ЗАПУСК ВРУЧНУЮ: implementation/high` — item P3.2 (вынос логики семи кросс-документных
команд `ai:*` на сервисы по утверждённой карте). §3 Routing: `implementation/high` · `manual` ·
`Review=full` — `/task:plan-exec` этот item обязан отклонить гейтом (он пинит sonnet/medium),
поэтому ниже ГОЛЫЙ ПРОМПТ, а не slash-команда. Альтернатива в одну строку: `/model sonnet` +
`/effort high` + `/task:plan-run 2026.07.28-EST-ARCH P3.2` (D18).

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | Семь кросс-документных команд переводятся на сервисы карты P3.1; владелец разбора канона промтов уезжает от сидера к сервису |

```
РЕЖИМ: исполнение item'а P3.2 плана 2026.07.28-EST-ARCH по замороженной карте
       plans/2026.07.28-EST-ARCH/root/service-layer-map.md (утверждена владельцем 2026-07-30);
       открытых решений у исполнителя НЕТ — расхождение карты с кодом = эскалация, не импровизация
ВХОД: plans/2026.07.28-EST-ARCH/phases/P3.md → «### P3.2» (все 16 полей) ·
      plans/2026.07.28-EST-ARCH/root/service-layer-map.md ЦЕЛИКОМ (особенно §0 границы слоёв,
        §1 форма отказа, §2 сейм прогресса, §3 первые семь строк, §4, §6, §7, §8, §11) ·
      plans/2026.07.28-EST-ARCH/findings/commands-and-layout-2026-07-29.md §1, §4, §5, §6 ·
      plans/2026.07.28-EST-ARCH/plan.md §2 Execution Rules и §5 D22, D32, D58, D59, D108 ·
      все семь файлов команд из Files item'а · database/seeders/PromptSeeder.php целиком ·
      скиллы dependency-injection, repositories, testing-rules ·
      .claude/rules/agelto-reviewer-overrides.md
СКОУП: сервисы по строкам карты для семи кросс-документных команд и перевод команд на них;
       владелец разбора канона промтов — App\Services\Llm\{PromptCanonReader,PromptCanonSynchronizer},
       сидер становится тонким вызывателем; App\Services\Llm\LlmProviderFactory снимает прямой
       make(PrismGeminiProvider) из ai:caches И второй match из AiRouteResolver (§8 карты);
       прямой Eloquent этих команд → репозитории по §6 карты (включая НОВЫЙ
       App\Repositories\Llm\PromptStoreRepository — его введение отнесено к P3 контрактом P2.1 §7);
       перемещение классов команд в app/Console/Commands/Procurement/Ai/ через git mv (D59);
       трейт App\Console\Concerns\RendersReport и App\Console\Support\ConsoleProgressReporter в
       границах этих команд; новые тесты на ai:caches и ai:prompts:sync; phpstan-baseline
       регенерируется под гейтом трёх величин (D58)
НЕ ТРОГАТЬ: сметные команды и их переименование (P3.3 — с учётом D108 их теперь 12);
       переезд файлов промтов (P3.4); раскладка DTO и репозиториев (P3.5); документация и
       правила ревью (P3.6); поведение команд — флаги, формат вывода, тексты сообщений и коды
       возврата прежние; тела и front-matter resources/ai/prompts/**; переработка ai:cost-report
       под слепок анализа (P4)
ВНИМАНИЕ (Pending Work P3.1, ещё НЕ закрыто plan-design): Files item'а P3.2 не несёт
       app/Services/Llm/AiRouteResolver.php, которого требует §8 карты, И несёт
       PromptsSuggestCommand.php, который после D108 принадлежит P3.3. Оба расхождения ТЗ с картой
       названы в Pending Work P3.1 — начинать item, не сверившись с ними, нельзя.
```

**Done:** фаза P1 закрыта 🟠 (4/8 🟢). Фаза P2 закрыта 🟠 (5/7 🟢, 2/7 🟠) — терминальна.
**P3.1 🟢 (`143d587`)** — контракт сервисного слоя `root/service-layer-map.md` утверждён владельцем:
14 разделов, 574 строки (на `143d587`). Решено: границы четырёх слоёв и три правила против «сервиса
поверх сервиса»; единая форма отказа (исключение `App\Exceptions\Procurement\OperationFailed` для
операционного отказа + типизированный `ItemOutcome` для отклонённого элемента партии, тексты
сообщений переезжают побайтово); сейм прогресса `ProgressReporter` с одним методом и опциональным
параметром метода — три потребителя названы (консоль `ConsoleProgressReporter`, очередь, админка P5);
карта 18 команд поимённо с целевым сервисом, остатком в команде и целевым каталогом класса (D59);
21 новая единица сервисного слоя; адреса 12 общих кусков; все 26 мест прямого Eloquent →
репозитории; владелец разбора канона промтов (`PromptCanonReader`); `LlmProviderFactory` вместо двух
`match` по драйверу; хардкод пути в замороженный план → `config('ai.fixtures.reports_dir')`;
каталоги команд `app/Console/Commands/Procurement/{Ai,Analyzers/Estimate}/`; общий базовый класс
команд НЕ заводится (общий код — трейты).

**Этот заход (`plan-run/opus-high`, session-модель владельца, D18):** P3.1 исполнен и закрыт;
Validation 1–11 зелёные (числа и команды — `phases/P3.md` P3.1 Completion Notes), диффа кода нет
(`git diff --stat 70aa998..143d587 -- app/ database/ resources/ tests/` — пусто), lint 0 ERROR /
0 WARN, K = 0. Гейт владельца пройден после ОДНОГО круга замечаний: владелец потребовал, чтобы всё
сметное — включая промты — несло префикс закупки смет; замечание закрыто **D108**
(`ai:prompts:suggest` → `estimate:prompts:suggest`, раннер `App\Services\Procurement\Analyzers\
Estimate\PromptSuggestionRunner`, исполнитель P3.3 вместо P3.2, разбивка команд 7↔11 → **6↔12**).
Правка замороженной карты P1.1 исполнена ровно тем путём, который предписывала её же строка класса 4
(D-запись со `supersedes`), — `root/naming-canon.md` обновлён в том же bookkeeping-коммите.

**Remaining:** 23 items ⬜ (P3 ×5 → P4 ×4 → P5 ×6 → P6 ×3 → P7 ×4). Гейт владельца впереди один —
P4.2. Батчи B1 (P4.3+P4.4), B2 (P7.1+P7.2) — `roadmap.md`.

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (правила исполнения), §3 Routing, §5
(**D1–D108**) · **ЗАМОРОЖЕНЫ: `root/naming-canon.md`** (правлен D108 — строка класса 4)**,
`root/document-type-axis.md`, `root/document-contour.md`, `root/document-analyzer-contract.md`,
`root/service-layer-map.md`** — правятся ТОЛЬКО новой D-записью со `supersedes` ·
`findings/commands-and-layout-2026-07-29.md` (вход P3.2/P3.3; семь расхождений с фактом кода названы
в `root/service-layer-map.md` §13) · `roadmap.md` · `phases/P2.md` (закрыта) · `phases/P3.md`.

**Lint:** `plan-lint --baseline HEAD plans/2026.07.28-EST-ARCH` — прогоняется в bookkeeping-коммите
этого захода (после перезаписи этого файла).

**Open risks:**
- **ТЗ P3.3 разошлось с картой по числам после D108** — Validation 3 ждёт 11 `estimate:`-команд
  (стало 12), Validation 4 ждёт 7 `ai:`-команд (стало 6), `PromptsSuggestCommand.php` стоит в
  `Files` P3.2 вместо P3.3. Без правки `plan-design` у P3.3 нет достижимого зелёного исхода (класс
  D89). Pending Work P3.1 п.1 — **блокер для P3.3, не для P3.2** (но сверка Files P3.2 нужна уже
  сейчас, см. п.2).
- **`Files` P3.2 не несёт `app/Services/Llm/AiRouteResolver.php`,** которого требует §8 карты.
  Pending Work P3.1 п.2.
- **Implementation Rule 4 P3.3 называет единственным изменением поведения путь отчёта корпуса,**
  а карта §9 нашла второе место того же класса (`BenchFormatsCommand:23,47`). Pending Work P3.1 п.3.
- **Контракт фазы P2 изменён (§1.5/§6.4, D106/D107), а сквозной reconcile «Потребляет ↔ Производит»
  делался 2026-07-29** — полезен новый `plan-design <ID> finish`; для P3.2 блокером не является.
- **Периметр `Files` у items этого плана систематически уже фактического** (P1.6, P2.4, P2.5).
  Перед запуском P3.2–P3.5 и P4.1 сверять `Files` грепом по FQN — на P3.2 это уже сработало
  (п. 2 выше).
- **Тот же класс, что вызвал эскалации P2.5 и P2.6, живёт у P3.2, P3.3, P3.5** — их Validation
  грепает файлы, переезжающие внутри того же item'а.
- **`ai:storage:lifecycle` и `ai:tools:doctor` — логически спорные имена** (ILM бакета и тулчейн
  стадии подготовки к AI не относятся). Предъявлены владельцу при закрытии P3.1, решение — оставить.
  Кандидат в P7.3/P7.4.
- **`FormatBenchRunner:275` — третья, частичная копия сброса `Analysis`** (карта §6 сводит только
  две из `EvalRunCommand`). Кандидат в отдельный item после P3.
- **Ключ `'estimates'` в ядре гейт (г) arch-теста P2.6 не ловит** — защита только докблоком §1.5
  инвариант 2 и код-ревью.
- **`PromptCritic` вне списка ядра §6.2**, поэтому инвариант «шаг анализатора = стадия извлечения»
  (D102) arch-тестом не защищён.
- **Ось типа документа у анализа ИЗВЛЕЧЕНИЯ пуста в боевом контуре**
  (`AnalysisStoreRepository::firstOrCreate()` `document_type` не пишет) — долг, кандидат за P3.5.
- **Два файла документации несут устаревшие пути перемещённых классов** —
  `docs/03_Dev/ai/feature-card-estimate-extraction.md:13` и `docs/03_Dev/ai/training-loop.md:21`.
- **Форма Validation «`config:cache` + `artisan test`» не имеет зелёного исхода в этом проекте**
  (кэш конфига замораживает dev-БД → `TestEnvironmentGuard` штатно прерывает прогон).
- **`--env=testing` НЕ переключает `DB_DATABASE`** — команду впредь префиксовать
  `-e DB_DATABASE=agelto_test`.
- **`docker compose exec app` недоступен в этой среде** — рабочий путь `docker exec agelto_app`.
- **`PYTHONPATH=packages/task/lib` из `plan-exec.md` не резолвится в этой раскладке** — рабочий путь
  `PYTHONPATH=/home/vostrikov/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (подтверждён
  фактическими прогонами на P2.6 и P3.1: `allow`/`allow`). Брать по умолчанию, не объявлять модуль
  недоступным.
- **`root/document-type-axis.md` §5 цитирует УСТАРЕВШИЕ номера строк Filament-форм** (писался на
  `dc0815c`).
- **Предпросмотр склеенного промта ДО прогона** — требование владельца, в план сознательно НЕ
  введено. Кандидат в scope P5.5 либо item P5.7.
- Наследуются из P1: журнальный разрыв P1.5–P1.8 (D99) · дыра протокола D98 (репозиторий
  `swissknifeman`) · `config/ai.php` несёт докблочные упоминания старых имён таблиц · строки Pending
  Work про `CLAUDE.md` живут до P7.4 (D60/D64) · боевой прогон P5.2 — за владельцем (D94).

**Workarounds/Deferred/Open questions:**
- workarounds: доказательство фальсифицируемости гейта МУТАЦИЕЙ вместо прогона на `<base>` — для
  гейтов над создаваемым артефактом (D100; применено к Validation 2 и 8a P3.1) · `docker exec
  agelto_app` вместо `docker compose exec app` · env-префикс на шаге `config:cache` · `pint --dirty`
  автофикс — точечными Edit-правками · `phpstan-baseline.neon` правится адресным удалением
  осиротевшей записи · гейт «дифф перемещённых файлов» требует `git diff -M` со старыми и новыми
  путями вместе · `$row->getAttribute('purpose')` вместо магического доступа к свойству ·
  `PYTHONPATH` через путь плагина `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib`
- deferred (введено картой P3.1 §12): транзакции вокруг перенесённой многомодельной записи · полный
  read/store-split `FixtureStoreRepository` · объединение датасетного и gold-путей создания рабочей
  копии (D57) · общая константа маски json-флагов (10 вхождений в 8 файлах) · расхождение
  «`ini_set` из конфига против хардкода» между `GoldBuildCommand:54` и `GoldImportCommand:55` ·
  переезд каталогов сервисов `Gold`/`Bench`/`Prepare` (D31)
- deferred (ранее): BigQuery-адаптер биллинга (D34) · HITL-правка эталона (D48) · пере-замер F1
  боевым прогоном (D52) · перенос дрейфа в `CLAUDE.md` — P7.4 (D60/D64) · общий инвариант «во всём
  сервисном слое нет прямых обращений к `LlmCall`» — в план НЕ вводится (D70/D79) · переезд экранов
  транспорта из `app/Filament/Resources/Procurement/Ai/**` (D96) · expression-индексы по
  `type_profile` · `resources/ai/prompts/README.md` пример front-matter без `document_type` ·
  `AnalysisSummary::$detailRoute` = `null` до появления предметного экрана сметы (P5) ·
  `docs/specs/v2/07-carryover-from-v1.md:107` и `CLAUDE.md:60` — P7.3/P7.4 (D104) · реопен-кандидат
  Validation 5 P2.5 (`\b`-форма) · перенос сравнения shadow-пары в `prc_ai_eval_results` · новый
  `plan-design finish` под изменённый контракт фазы P2
- open_questions: незакрытых вопросов В ГРАНИЦАХ плана нет. Остался один гейт владельца — P4.2.
  Вне плана: P7.6 плана `2026.07.26-EST-TRAIN` (боевое замыкание петли тюнинга)
