# HANDOFF — 2026-07-30 — after P2.6

**Next:** `run-items: task:plan-run 2026.07.28-EST-ARCH P2.7` — новый item фазы P2, заведённый
находкой P2.6 (D105): развязать два остаточных ядерных call-site'а сметы по дополненному контракту
P2.1 (§1.5/§6.4). ТЗ доведено до DoR, открытых решений у исполнителя нет.

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | — |
| Context | same-session — item |
| Суть | Сметное структурное правило уезжает в DTO ответа, сравнение теневого прогона — на анализатор; после item'а периметр §6.2 чист и P2.6 разблокирован |

```
/task:plan-run 2026.07.28-EST-ARCH P2.7

РЕЖИМ: исполнение НОВОГО item'а по замороженному ТЗ (не реопен, не редизайн) — сеймы выбраны и
       вписаны в контракт заходом дизайна 2026-07-30, исполнителю решать нечего
ВХОД: plans/2026.07.28-EST-ARCH/phases/P2.md → «### P2.7» (все 16 полей) ·
      plans/2026.07.28-EST-ARCH/root/document-analyzer-contract.md §1.1, §1.5, §6.2–§6.4 ·
      plans/2026.07.28-EST-ARCH/findings/core-callsite-seams-2026-07-30.md (весь) ·
      plans/2026.07.28-EST-ARCH/findings/core-arch-gate-2026-07-30.md §1 ·
      plans/2026.07.28-EST-ARCH/plan.md §2 (правила исполнения) и D105–D107
СКОУП: 1) интерфейс App\Services\Llm\Contracts\StructurallyValidatable + перенос правила
          монотонности/уникальности row_index в RowsChunkResponse ПОБАЙТОВО (сообщения не
          переписывать);
       2) StructuredResponseValidator: ветка instanceof RowsChunkResponse → instanceof
          StructurallyValidatable;
       3) интерфейс App\Services\Procurement\Contracts\SupportsResultComparison +
          реализация на EstimateAnalyzer (конструкторная инъекция EvalMetrics, разбор ключа
          'estimates' ВНУТРИ анализатора);
       4) ShadowAnalysisJob: снять EvalMetrics из handle()/run()/compareWithPrimary(), сравнение —
          через уже инъектированный DocumentAnalyzerRegistry, явные ветки null для DocumentTypeScope
          и для анализатора без capability;
       5) тесты: хелпер runShadowJob() под новую сигнатуру, новый тест «кросс-документный прогон
          даёт comparison = null», новый тест RowsChunkResponse::structuralError();
       6) закрытие item'а полным протоколом §8 (item-коммит по Files → бухгалтерия → handoff → lint).
НЕ ТРОГАТЬ: tests/Unit/Llm/StructuredResponseValidatorTest.php — сторож паритета, правка ЗАПРЕЩЕНА
       (Implementation Rule 2; краснеет — значит сломан перенос, чинится код) · arch-тест и
       фиктивный второй тип — предмет P2.6 · список неймспейсов §6.2 и форму гейта §6.3 контракта
       (D105: не сужать и не ужесточать) · формулы/FIELD_MAP/допуски EvalMetrics · потребителей
       EvalMetrics вне периметра §6.2 (EvalRunCommand, FormatBenchRunner — снимает P3) · неймспейс
       RowsChunkResponse (DTO сметы стоят до P3.5, D19/D33) · блок KNOWN DEVIATION про
       prc_ai_eval_results в докблоке ShadowAnalysisJob · схему БД, значения enum'ов,
       config('ai.analyzers')
```

**Done:** фаза P1 закрыта 🟠 (4/8 🟢). **P2.1 🟢** (`fcc0259`) — контракт `DocumentAnalyzer` заморожен
гейтом владельца. **P2.2 🟢** (`c485dab`) — маршрутизация типизирована на
`AiDocumentType|DocumentTypeScope`. **P2.3 🟢** (`2deb5c1`) — реестр анализаторов, ветвление скрининга
и re-run через него. **P2.4 🟠** (`e06434c`) — 16 сметных единиц + `EstimateExtractionJob` в
`**\Analyzers\Estimate\**`. **P2.5 🟠** (`936911f`) — `PromptStep`/`LlmPurpose` расщеплены на
интерфейсы + ядерные/типовые enum'ы, валидация по реестру на четырёх путях записи.

**Этот заход (`plan-design P2.6`, opus/high — кода не написано, коммит только плановый):**
эскалация P2.6 снята решением владельца («без хвостов и архитектурно верно» → путь A + опциональный
capability-интерфейс).

1. **P2.6 разблокирован** (🔴 → ⬜): блок эскалации заменён записью о снятии; ТЗ приведено к РАБОЧЕЙ
   форме arch-гейта (Implementation Rules 9–11 — таргеты поодиночке, `->ignoring()` запрещён,
   динамический периметр 60 классов, шаблон (г) не ужесточается), Validation 1–4 переписаны с
   доказательством фальсифицируемости МУТАЦИЕЙ (гейты над создаваемым артефактом и сторож инварианта
   красного исхода на `<base>` не имеют по построению).
2. **Заведён item P2.7** — развязка обоих нарушителей, 16 полей, `implementation/high` · `manual` ·
   `Review=full`. Порядок исполнения внутри фазы: **P2.7 → P2.6**.
3. **Замороженный контракт P2.1 дополнен** (D106/D107, `supersedes` заморозки §1): §1.5
   (опциональные capability-интерфейсы типа) и §6.4 (структурная проверка ответа на стороне DTO) +
   уточнение периметра в §6.3 (D105). Семь исходных решений не переписаны ни в одном пункте.
4. **Слои знаний:** `findings/core-callsite-seams-2026-07-30.md` (инвентарь потребителей, ловушки
   исполнения, две пробы шаблона (г) с выводами), `brief/01-refinements.md` (датированный блок с
   решением владельца и его ценой), `index.md`, `open-questions.md` (OQ-16/OQ-17 → закрытые).

**Remaining:** 25 items ⬜ (**P2.7 → P2.6** → P3 ×6 → P4 ×4 → P5 ×6 → P6 ×3 → P7 ×4). Батчи B1/B2 и
два оставшихся гейта владельца (P3.1, P4.2) — `roadmap.md`.

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (правила исполнения), §3 Routing, §5
(**D1–D107**) · **ЗАМОРОЖЕНЫ: `root/naming-canon.md`, `root/document-type-axis.md`,
`root/document-contour.md`, `root/document-analyzer-contract.md`** — правятся ТОЛЬКО новой D-записью
со `supersedes` (последняя такая правка — D106/D107) · `findings/core-callsite-seams-2026-07-30.md`
и `findings/core-arch-gate-2026-07-30.md` (вход P2.7 и P2.6) ·
`research/03-step-purpose-seam-callsites.md` · `roadmap.md` · `phases/P2.md` (P2.1–P2.5 Completion
Notes, ТЗ P2.7 и P2.6).

**Lint:** см. отчёт сессии (плановый коммит этого захода).

**Open risks:**
- **Контракт фазы P2 изменён, а сквозной reconcile «Потребляет ↔ Производит» делался 2026-07-29** —
  до появления §1.5/§6.4. Перед уходом фазы P3 в исполнение полезен новый
  `plan-design <ID> finish`; для запуска P2.7/P2.6 блокером НЕ является (изменение аддитивно и
  внутри фазы P2, чьи items его и производят).
- **Контракт P2.1 утверждал НЕПРОВЕРЕННЫЙ факт** («периметр §6.2 — ровно то, что фаза P2
  вычищает»), и этого не заметили ни P2.1, ни P2.4, ни P2.5, ни четыре захода `plan-audit design`.
  Класс дефекта — «утверждение о репозитории, не подкреплённое прогоном»; уточнение вписано, но
  других таких утверждений в контрактах никто не искал.
- **Ключ `'estimates'` в ядре гейт (г) НЕ ловит** (шаблон — `\bEstimate\b`/`'estimate'`). После P2.7
  строк не остаётся, но защиты от их возврата arch-тест не даёт — только докблок §1.5 инвариант 2.
- **`PromptCritic` остаётся вне списка ядра §6.2**, поэтому инвариант «шаг анализатора = стадия
  извлечения» (D102) arch-тестом P2.6 НЕ защищён — только докблоком и тестом.
- **Периметр `Files` у items этого плана систематически уже фактического** (P1.6, P2.4, P2.5). Перед
  запуском P3.2–P3.5 и P4.1 сверять `Files` грепом по FQN.
- **Тот же класс, что вызвал эскалации P2.5 и P2.6, живёт у P3.2, P3.3, P3.5** — их Validation
  грепает файлы, переезжающие внутри того же item'а.
- **Validation 5 item'а P2.5 несёт грепу без границы слова** — реопен-кандидат `plan-design`
  (форма `\b(PromptStep|LlmPurpose)::cases`, подтверждена в Completion Notes P2.5 — 0 строк).
- **Ось типа документа у анализа ИЗВЛЕЧЕНИЯ пуста в боевом контуре**
  (`AnalysisStoreRepository::firstOrCreate()` `document_type` не пишет) — долг, кандидат за P3.5.
- **Два файла документации несут устаревшие пути перемещённых классов** —
  `docs/03_Dev/ai/feature-card-estimate-extraction.md:13` и `docs/03_Dev/ai/training-loop.md:21`.
- **Форма Validation «`config:cache` + `artisan test`» не имеет зелёного исхода в этом проекте**
  (кэш конфига замораживает dev-БД → `TestEnvironmentGuard` штатно прерывает прогон).
- **`--env=testing` НЕ переключает `DB_DATABASE` в этой раскладке** — команду впредь префиксовать
  `-e DB_DATABASE=agelto_test`, не полагаться на `--env`.
- **`root/document-type-axis.md` §5 цитирует УСТАРЕВШИЕ номера строк Filament-форм** (писался на
  `dc0815c`) — наследуется из P2.1, не переоткрывалось.
- **Предпросмотр склеенного промта ДО прогона** — требование владельца, в план сознательно НЕ
  введено. Кандидат в scope P5.5 либо item P5.7.
- Наследуются из P1: журнальный разрыв P1.5–P1.8 (D99) · дыра протокола D98 (репозиторий
  `swissknifeman`) · `config/ai.php` несёт докблочные упоминания старых имён таблиц · четыре+одна
  строки Pending Work про `CLAUDE.md` живут до P7.4 (D60/D64) · боевой прогон P5.2 — за владельцем
  (D94).

**Workarounds/Deferred/Open questions:**
- workarounds: доказательство фальсифицируемости гейта МУТАЦИЕЙ вместо прогона на `<base>` — для
  гейтов над создаваемым артефактом и для сторожей инварианта (D100, применено к Validation 1–4
  P2.6) · `docker exec agelto_app` вместо `docker compose exec app` · env-префикс на шаге
  `config:cache` · `pint --dirty` автофикс — точечными Edit-правками · `phpstan-baseline.neon`
  правится адресным удалением осиротевшей записи · гейт «дифф перемещённых файлов» требует
  `git diff -M` со старыми и новыми путями вместе · `$row->getAttribute('purpose')` вместо
  магического доступа к свойству
- deferred: BigQuery-адаптер биллинга (D34) · HITL-правка эталона (D48) · пере-замер F1 боевым
  прогоном (D52) · остаточная дупликация `estimate:dataset:import` (D57) · перенос дрейфа в
  `CLAUDE.md` — P7.4 (D60/D64) · общий инвариант «во всём сервисном слое нет прямых обращений к
  `LlmCall`» — в план НЕ вводится (D70/D79) · переезд экранов транспорта из
  `app/Filament/Resources/Procurement/Ai/**` — вне D22 (D96) · expression-индексы по `type_profile`
  — не заводятся до 10 000 строк реестра · `resources/ai/prompts/README.md` пример front-matter без
  `document_type` · `config/ai.php` докблочные упоминания старых имён таблиц · раскладка DTO сметы
  под сегмент `Analyzers\Estimate` — решает P3.5 · подкаталоги внутри `Analyzers/Estimate` ·
  `AnalysisSummary::$detailRoute` = `null` до появления предметного экрана сметы (P5) ·
  `docs/specs/v2/07-carryover-from-v1.md:107` и `CLAUDE.md:60` про состав носителя — P7.3/P7.4
  (D104) · реопен-кандидат Validation 5 P2.5 (`\b`-форма) — `plan-design` · перенос сравнения
  shadow-пары в `prc_ai_eval_results` (Known Deviation докблока `ShadowAnalysisJob`, требует
  миграции — отдельное решение) · новый `plan-design finish` под изменённый контракт фазы P2
- open_questions: незакрытых вопросов В ГРАНИЦАХ плана нет — OQ-16/OQ-17 закрыты решением владельца
  2026-07-30 (D105–D107). Остаются два гейта владельца впереди (P3.1, P4.2). Вне плана: P7.6 плана
  `2026.07.26-EST-TRAIN` (боевое замыкание петли тюнинга)
