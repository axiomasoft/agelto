# HANDOFF — 2026-07-30 — after P2.6

**Next:** `owner-gate: /model opus + /effort high + task:plan-run 2026.07.28-EST-ARCH P3.1` — первый
item фазы P3, гейт владельца: контракт `root/service-layer-map.md` (карта «18 команд → сервис»,
границы слоёв, сейм прогресса, единая форма отказа). Фаза P2 терминальна (7/7, 🟠 Done with
deviations) — P2.6 закрыл её машинным доказательством D14/D26.

| Параметр | Значение |
|:--|:--|
| Model class | frontier |
| Effort | high |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | Design-item с гейтом владельца: карта «команда → сервис» для 18 консольных команд, вход для P3.2–P3.3 |

```
/model opus
/effort high
/task:plan-run 2026.07.28-EST-ARCH P3.1

РЕЖИМ: design-item с гейтом владельца (Exec=manual, §3 Routing) — item производит ТОЛЬКО документ
       `root/service-layer-map.md`, код не пишется и не правится ни в одном файле вне Files
ВХОД: plans/2026.07.28-EST-ARCH/phases/P3.md → «### P3.1» (все 16 полей) ·
      plans/2026.07.28-EST-ARCH/findings/commands-and-layout-2026-07-29.md (весь) ·
      plans/2026.07.28-EST-ARCH/root/naming-canon.md (карта имён команд, P1.1) ·
      plans/2026.07.28-EST-ARCH/root/document-analyzer-contract.md (конвенция каталога типа, P2.1) ·
      plans/2026.07.28-EST-ARCH/plan.md §2 Execution Rules и §5 D4, D12, D31 ·
      app/Console/Commands/Ai/EvalRunCommand.php целиком (самая нагруженная команда, 622 строки) ·
      app/Console/Commands/Ai/{EvalDiffCommand,PromptsSuggestCommand}.php целиком (дубль резолва) ·
      скиллы laravel-structure, dependency-injection, codebase-design
СКОУП: РЕШЕНИЕ по каждой из 18 команд (сервис/неймспейс/что остаётся); граница «команда ↔ сервис ↔
       Action ↔ репозиторий»; сейм прогресса для ТРЁХ потребителей (консоль/очередь/админка);
       единая форма отказа; общие куски §3 findings — куда уезжают; владелец разбора файлового
       канона промтов; классификация ai:prompts:suggest (Implementation Rule 6 — пограничный
       случай, решается явно); целевой каталог КАЖДОГО из 18 классов команд (D59) + судьба общего
       базового класса/трейта; по каждому решению — отвергнутая альтернатива; запись
       root/service-layer-map.md; предъявление владельцу и фиксация «утверждаю» в Update Log.
НЕ ТРОГАТЬ: любой код вне Files (item производит документ, не диффа кода); переименование команд
       (P3.3 — карта имён принадлежит P1.1); переезд промтов (P3.4); раскладка DTO/репозиториев
       (P3.5, уже решена D31); экраны P5.
```

**Done:** фаза P1 закрыта 🟠 (4/8 🟢). Фаза P2 закрыта 🟠 (5/7 🟢, 2/7 🟠) — **терминальна**.
P2.1 🟢 (`fcc0259`) — контракт `DocumentAnalyzer` заморожен гейтом владельца. P2.2 🟢 (`c485dab`) —
маршрутизация типизирована на `AiDocumentType|DocumentTypeScope`. P2.3 🟢 (`2deb5c1`) — реестр
анализаторов, ветвление скрининга и re-run через него. P2.4 🟠 (`e06434c`) — 16 сметных единиц +
`EstimateExtractionJob` в `**\Analyzers\Estimate\**`. P2.5 🟠 (`936911f`) — `PromptStep`/`LlmPurpose`
расщеплены на интерфейсы + ядерные/типовые enum'ы, валидация по реестру на четырёх путях записи.
P2.7 🟢 (`355a6ce`) — `StructurallyValidatable`/`SupportsResultComparison` развязывают оба ядерных
call-site'а сметы. **P2.6 🟢 (`708bb7e`)** — arch-тест «ядро не знает про тип документа» по восьми
неймспейсам §6.2 (62 класса, вычитание сегментом `\Analyzers\`) и фиктивный второй тип
`AiDocumentType::TechSpec` (`tests/Support/Analyzer/**`, свой job, сводка через реестр, БЕЗ
`SupportsResultComparison`) — приёмка D14/D26 доказана машинно, продуктовый код фазой не тронут.

**Этот заход (`plan-run/sonnet-high`, session-модель владельца, D18):** P2.6 исполнен и закрыт по
замороженному ТЗ (эскалация снята 2026-07-30 заходом `plan-design P2.6`, D105–D107; периметр §6.2
чист после P2.7). Два новых теста-файла-хелпера в `tests/Support/Analyzer/**`
(`TechSpecFakeAnalyzer` implements `DocumentAnalyzer`, НЕ `SupportsResultComparison`;
`TechSpecFakeExtractionJob` — `ShouldQueue`), один arch-тест
(`tests/Unit/Architecture/CoreKnowsNoDocumentTypeTest.php`, Finder-сбор периметра по восьми
неймспейсам, таргеты `toUse()` поодиночке, гейт (г) регистрочувствительным `\bEstimate\b`/`'estimate'`
с вырезанным `AiDocumentType::Estimate`), один интеграционный тест
(`tests/Feature/Procurement/Ai/SecondDocumentTypeTest.php`, 3 теста: дiспатч своего job'а, сводка
через реестр, отсутствие `SupportsResultComparison`). Периметр — **62** класса на `708bb7e`
(было 60 на `753cc0b` до P2.7 — расхождение объяснено, не ошибка сборки: два легитимных файла P2.7
внутри уже перечисленных неймспейсов §6.2). Фальсифицируемость подтверждена ТРЕМЯ мутациями с
откатом (см. `phases/P2.md` P2.6 Completion Notes). Полный сюит (774 passed, 1 skipped — было
768/1), phpstan (без новых ошибок, baseline не вырос), pint — зелёные. `git diff --stat
3374445..708bb7e -- app/ database/ resources/` — пусто (продуктовый код не тронут).

**Known Deviation (process, статус не изменён):** Pre-mutation Task Contract-гейт (`plan-exec.md`
п. 2) и журнальная старт-запись выполнены ПОСЛЕ написания/коммита тестов, а не до — порядок
протокольных шагов нарушен, итог гейта (`allow`) от этого не изменился. Деталь — `phases/P2.md`
P2.6 Known Deviations.

**Remaining:** 24 items ⬜ (P3 ×6 → P4 ×4 → P5 ×6 → P6 ×3 → P7 ×4, фазы P1/P2 закрыты). Гейты
владельца впереди — P3.1 (следующий), P4.2. Батчи B1 (P4.3+P4.4), B2 (P7.1+P7.2) — `roadmap.md`.

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (правила исполнения), §3 Routing, §5
(**D1–D107**) · **ЗАМОРОЖЕНЫ: `root/naming-canon.md`, `root/document-type-axis.md`,
`root/document-contour.md`, `root/document-analyzer-contract.md`** — правятся ТОЛЬКО новой D-записью
со `supersedes` · `findings/commands-and-layout-2026-07-29.md` (вход P3.1) · `roadmap.md` ·
`phases/P2.md` (закрыта, все 7 items с Completion Notes + Phase Handoff) · `phases/P3.md`
(P3.1–P3.6 ТЗ, DoR).

**Lint:** `plan-lint --baseline HEAD plans/2026.07.28-EST-ARCH` — прогоняется в bookkeeping-коммите
этого захода (после перезаписи этого файла).

**Open risks:**
- **Контракт фазы P2 изменён (§1.5/§6.4, D106/D107), а сквозной reconcile «Потребляет ↔ Производит»
  делался 2026-07-29** — до появления §1.5/§6.4. Перед уходом фазы P3 в исполнение полезен новый
  `plan-design <ID> finish`; для запуска P3.1 блокером НЕ является (P3.1 сам производит контракт,
  не потребляет P2 сверх уже названного).
- **Ключ `'estimates'` в ядре гейт (г) не ловит** (шаблон — `\bEstimate\b`/`'estimate'`). P2.7
  убрал обе строки вместе с импортом; арх-тест P2.6 форму (г) НЕ ужесточает (D105), поэтому будущий
  возврат ключа `'estimates'` в ядро гейтом (г) поймать НЕЛЬЗЯ — защита только докблоком §1.5
  инвариант 2 и код-ревью.
- **`PromptCritic` остаётся вне списка ядра §6.2**, поэтому инвариант «шаг анализатора = стадия
  извлечения» (D102) arch-тестом P2.6 НЕ защищён — только докблоком и тестом.
- **Периметр `Files` у items этого плана систематически уже фактического** (P1.6, P2.4, P2.5);
  P2.6/P2.7 прошли без отклонения. Перед запуском P3.2–P3.5 и P4.1 сверять `Files` грепом по FQN.
- **Тот же класс, что вызвал эскалации P2.5 и P2.6, живёт у P3.2, P3.3, P3.5** — их Validation
  грепает файлы, переезжающие внутри того же item'а.
- **Ось типа документа у анализа ИЗВЛЕЧЕНИЯ пуста в боевом контуре**
  (`AnalysisStoreRepository::firstOrCreate()` `document_type` не пишет) — долг, кандидат за P3.5.
- **Два файла документации несут устаревшие пути перемещённых классов** —
  `docs/03_Dev/ai/feature-card-estimate-extraction.md:13` и `docs/03_Dev/ai/training-loop.md:21`.
- **Форма Validation «`config:cache` + `artisan test`» не имеет зелёного исхода в этом проекте**
  (кэш конфига замораживает dev-БД → `TestEnvironmentGuard` штатно прерывает прогон).
- **`--env=testing` НЕ переключает `DB_DATABASE`** — команду впредь префиксовать
  `-e DB_DATABASE=agelto_test`, не полагаться на `--env`.
- **`docker compose exec app` недоступен в этой среде** (`services.minio.environment.
  MINIO_ROOT_PASSWORD` не задан) — рабочий путь `docker exec agelto_app`.
- **`PYTHONPATH=packages/task/lib` из `plan-exec.md` не резолвится в этой раскладке** (репо
  `agelto`, не `swissknifeman`) — рабочий путь `PYTHONPATH=/home/vostrikov/.claude/plugins/cache/
  swissknifeman/task/0.9.0/lib` (тот же пакет, что раздаёт саму команду `task:plan-run`); подтверждён
  фактическим прогоном на P2.6 (`allow`/`allow`). Предыдущий аудит фазы P1 (F2) уже находил, что
  заявления о недоступности пакета опровергаются фактами той же сессии — правило подтверждено
  повторно, брать этот путь по умолчанию, не объявлять модуль недоступным.
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
  магического доступа к свойству · `PYTHONPATH` через путь плагина `~/.claude/plugins/cache/
  swissknifeman/task/0.9.0/lib` вместо буквального `packages/task/lib` из `plan-exec.md`
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
- open_questions: незакрытых вопросов В ГРАНИЦАХ плана нет. Остаётся один гейт владельца впереди
  (P3.1 — следующий шаг; P4.2 — дальше). Вне плана: P7.6 плана `2026.07.26-EST-TRAIN` (боевое
  замыкание петли тюнинга)
