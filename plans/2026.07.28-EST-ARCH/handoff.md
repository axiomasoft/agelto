# HANDOFF — 2026-07-29 — after P1.7

**Next:** `/task:plan-exec 2026.07.28-EST-ARCH P1.8` — единственный `plan-exec` фазы P1 (Routing
`implementation/medium`): оси предмета в `prc_ai_prompt_suggestions` по уже пиненному D21
носителю.

| Параметр | Значение |
|:--|:--|
| Model class | implementation (`sonnet`) |
| Effort | medium |
| Capabilities | — |
| Context | continue (`/clear`) — ручной item |
| Суть | Локальный срез: одна таблица, существующие enum'ы (`AnalysisStep`), детерминированная приёмка (D21) |

```
/task:plan-exec 2026.07.28-EST-ARCH P1.8
```

`Exec = plan-exec` (§3 Routing) — команда пинит `sonnet/medium` сама, ритуал `/model`+`/effort` не
нужен. P1.8 — последний item фазы P1: после его закрытия фаза P1 становится терминальной (8/8) и
следующий шаг — `/task:plan-close 2026.07.28-EST-ARCH P1`, не `plan-run`/`plan-exec`.

**Правило исполнения гейтов (D89 + трактовка владельца 2026-07-29) — читать ДО первого коммита
ЛЮБОГО item'а.** Каждая строка `Validation`, являющаяся греп/`git`-командой, прогоняется на
`<base>` и печатается в Completion Notes рядом с выводом «после». Исходы: на `<base>` красный →
норма; на `<base>` уже зелёный → эскалация §10 до написания кода; зелёный недостижим в границах
`Scope` → эскалация §10.

**Правило D94:** боевые LLM-прогоны запускает ВЛАДЕЛЕЦ. Агент пишет код и тесты (LLM в тестах —
только `FakeLlmProvider`); item, чья Validation требует боевого вызова, закрывается с записью
«прогон за владельцем» и неисполненным не считается. Прямо касается P5.2 и любых
`estimate:bench:*`/`estimate:eval:*` на живом провайдере.

**Инфраструктурное ограничение ЭТОЙ раскладки репозитория (найдено на P1.5, актуально и для P1.8):**
`packages/task/lib` (`task.task_contract`/`task.route_evidence`) и
`${CLAUDE_PLUGIN_ROOT}/scripts/plan-lint.py` НЕ доступны из `agelto` (репозиторий не несёт их,
`CLAUDE_PLUGIN_ROOT` не задан в среде исполнения) — Pre-mutation/Pre-final гейт `plan-exec.md`
п.2/п.7 и `plan-lint` фиксируются как «недоступен» (fail-closed текстом, не молчаливым `allow`),
закрытие опирается на публично воспроизводимые факты самой `Validation` item'а (D77). Это
ограничение среды, не решение исполнителя — называть его в Completion Notes каждого следующего
item'а, пока раскладка не изменится.

**Инфраструктурная деталь среды исполнения (найдено на P1.7):** `docker compose exec` в этой сессии
падал на интерполяции переменной `MINIO_ROOT_PASSWORD` (`.env`-переменная не резолвилась в среде
`Bash`-инструмента), хотя стек уже поднят (`docker ps` показывает все контейнеры `agelto_*`
healthy). Обходной путь — `docker exec agelto_app <команда>` на тот же контейнер вместо
`docker compose exec app <команда>`; команды и их аргументы идентичны. Если у следующего item'а та
же ошибка — использовать этот же обходной путь, не тратить цикл на диагностику заново.

**Новый паттерн, доказавший себя на P1.7 — «периметр грепа шире Files item'а, если Files
недооценил coupling»:** переименование СТРОКОВОГО значения enum'а (не только имени кейса) может
задеть JSON/array-фикстуры вне списка `Files`, которые грепом по `EnumName::CaseName\b` не ловятся
(значение там — голый литерал `'old_value'`, а не ссылка на кейс). Если `artisan test` красный
именно на кастинге/валидации этого значения (не на логике) — это тот же класс, что переименование
файла/класса: чинится ПОДСТАНОВКОЙ нового значения на месте старого, статус остаётся 🟠 (дифф вне
`Files`), а не эскалация. Держать в уме при следующих item'ах, меняющих строковые значения enum'ов
(`PromptStep`/`LlmPurpose` в P2.5 — тот же риск-класс, периметр стоит проверять шире одних
call-site'ов кейса).

**Done:** P1.7 закрыт (🟠 Done with deviations) — item-коммит `d676517`, `<base>=069013b`.
Переименования без схемной связи + переезд `Concerns` (D23):

- **Триада вызова LLM:** `App\Repositories\Llm\AiCallStoreRepository` →
  `App\Repositories\Llm\LlmCallStoreRepository` (файл + класс), потребители `LlmCallRecorder.php`,
  `LlmCallRecorderTest.php`.
- **Коллизия `Estimate` разведена:** `EstimateType::Estimate = 'estimate'` →
  `EstimateType::LocalEstimate = 'local_estimate'` (значение и кейс); `AiDocumentType::Estimate`
  — без изменения (D16 SSOT). Синхронно правлены `EstimatePassport.php`, `EstimateContractsTest.php`,
  `docs/specs/v2/08-estimate-extraction.md` §1.1/§3.1/§3.5.
- **Трейты `HasErrorBlock`/`HasLease`:** `App\Models\Procurement\Concerns` → `App\Models\Concerns`
  (D23); потребители `Document.php`, `Analysis.php`; докблоки `PipelineJob.php` и
  `docs/03_Dev/adr/0009-job-owns-document-transition.md` (G3, точечно — только путь, D68).
- Полные факты, Validation 1–8 с числами «до/после» на `069013b`/`d676517` —
  `phases/P1.md` P1.7 Completion Notes.
- **🟠 (Known Deviations, три пункта):** пять тестов вне `Files`, ломавшихся строковым значением
  `'estimate'` (`EstimateToContractMapperTest`, `GoldValidatorTest`, `DatasetImportTest`,
  `DatasetExportTest`, `EstimateRowContractTest`) — грепом Validation 2 не ловятся (голый литерал,
  не ссылка на кейс); инфраструктурный разрыв task_contract/plan-lint (среда); недооценённая на
  дизайне coupling-поверхность строкового значения класса 3 карты (находка для `plan-design`, не
  blocker).

**Remaining:** 30 item ⬜ Not started, 7 🟢/🟠 (P1.1–P1.7). Фаза P1 — 7/8, последний item — P1.8.
Порядок: P1.8 → P2 (6) → P3 (6) → P4 (4) → P5 (6) → P6 (3) → P7 (4). Батчи B1/B2 и три оставшихся
гейта владельца (P2.1, P3.1, P4.2) — `roadmap.md`.

**Sources of truth:** скилл `plan-protocol` (схемы, статусы, DoR) ·
`plans/2026.07.28-EST-ARCH/plan.md` §2 (правила исполнения) и §5 (**D1–D96**) ·
**`root/naming-canon.md`, `root/document-type-axis.md`, `root/document-contour.md` — ЗАМОРОЖЕНЫ** ·
`research/02-schema-impact-map.md` · `roadmap.md` · `phases/P1.md` (P1.7 Completion Notes) ·
`brief/{00-brief,01-refinements}.md` · `open-questions.md`.

**Open risks:**
- **Инфраструктурный разрыв task_contract/plan-lint** (см. блок выше) — сохраняется для ВСЕХ
  следующих items; называть явно в каждом Completion Notes, не молчать.
- **`docker compose exec` падает на интерполяции `MINIO_ROOT_PASSWORD`** в этой сессии — обходной
  путь `docker exec agelto_app` (см. блок выше); не инфраструктура репозитория, деталь среды
  исполнения текущей сессии.
- **`config/ai.php` несёт докблочные упоминания старых имён таблиц** (комментарии) — правится при
  следующем касании файла (P1.5 Open risks, не в Scope P1.6/P1.7).
- Три строки Pending Work про `CLAUDE.md` живут до P7.4 (D60/D64) — четвёртая добавлена P1.7.
  Боевой прогон P5.2 — за владельцем (D94).
- **Периметр coupling для строковых значений enum'ов** — держать в уме на P2.5 (расщепление
  `PromptStep`/`LlmPurpose`): проверять не только call-site'ы кейса, но и голые литералы значений
  в тестовых фикстурах (находка P1.7, см. блок выше).

**Workarounds/Deferred/Open questions:**
- workarounds: доказательство фальсифицируемости гейта МУТАЦИЕЙ вместо прогона на `<base>` — для
  гейтов над создаваемым артефактом (решение владельца 2026-07-29); применено в P1.1–P1.6, впредь
  требуется у P2.1, P3.1, P4.2 · `pint --dirty` автофикс — точечными Edit-правками (host-запуск
  заблокирован permission-гейтом сессии, P1.5) · `docker exec agelto_app` вместо
  `docker compose exec app` при сбое интерполяции `.env` (P1.7)
- deferred: ревизия формы оставшихся гейтов — снята D89 · BigQuery-адаптер биллинга (D34) ·
  HITL-правка эталона (D48) · пере-замер F1 боевым прогоном (D52) · остаточная дупликация
  `estimate:dataset:import` (D57) · перенос дрейфа в `CLAUDE.md` — P7.4 (D60/D64) · общий инвариант
  «во всём сервисном слое нет прямых обращений к `LlmCall`» — в план НЕ вводится (D70/D79) ·
  переезд экранов транспорта из `app/Filament/Resources/Procurement/Ai/**` — вне D22 (D96) ·
  expression-индексы по `type_profile` — не заводятся до 10 000 строк реестра (§6.4) ·
  эксплуатационный отбор «какие документы пускать в дорогой разбор» — вне схемы (D92) ·
  `resources/ai/prompts/README.md` пример front-matter без `document_type` — правится при
  следующем касании промтов · `config/ai.php` докблочные упоминания старых имён таблиц — правятся
  при следующем касании файла
- open_questions: в границах плана — НЕТ. OQ-1…OQ-14 закрыты. Вне плана: P7.6 плана
  `2026.07.26-EST-TRAIN` (боевое замыкание петли тюнинга) — тратит реальные деньги, несёт два
  гейта владельца
