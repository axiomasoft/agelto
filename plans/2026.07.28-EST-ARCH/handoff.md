# HANDOFF — 2026-07-29 — after P2.2

**Next:** «ЗАПУСК ВРУЧНУЮ: implementation/high» — P2.3 «Развязка скрининга и операторского re-run
через реестр». `Exec = manual` по §3 Routing (переписывается ЕДИНСТВЕННАЯ точка ветвления
пайплайна — ошибка означает, что документы молча перестают уходить в извлечение; задевается
семантика диспатча job'а, D39 проекта). `/task:plan-exec` этот item отклонит гейтом п. 2
(`ROUTING-BLOCKED`) — не пытаться.

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | P2.3 — интерфейс `DocumentAnalyzer` + реестр по контракту P2.1, анализатор сметы как первая реализация, перевод `ScreeningOutcomeHandler::branchByType()` и `RerunAnalysisAction` на реестр, `outcome` события выводится из типа |

```
/model opus
/effort high
/task:plan-run 2026.07.28-EST-ARCH P2.3
```

**Done:** фаза P1 закрыта 🟠 (4/8 🟢, детали — `phases/P1.md` → `## Phase Handoff`). **P2.1 закрыт
🟢:** контракт `DocumentAnalyzer` заморожен (`fcc0259` + bookkeeping). **P2.2 закрыт 🟢
(`c485dab` item + bookkeeping):** `AiRouteResolver::{resolve,candidatesFor,shadowRoute}` и
`LlmRouteReadRepository::{activeCandidatesFor,activeShadowRouteFor}` типизированы на
`AiDocumentType|DocumentTypeScope`; 12 call-site'ов сняли литералы `'estimate'`/`'any'`
(`ScreeningProcessor`, `ScreeningOutcomeHandler` — только вызов резолвера, `PromptCritic`,
`EvalRunCommand` ×2, `FormatBenchRunner`, `ExtractionOrchestrator` ×2, `RerunRangeAction`,
`AiRoutingSeeder`, `LlmRouteFactory`); неизвестный `document_type` теперь рвётся
`AiDocumentType::from()` (`ValueError`) вместо молчаливого резолва в кросс-документный маршрут;
негативный тест на типизацию сигнатуры (`TypeError`). Полные факты — `phases/P2.md` → P2.2
Completion Notes.

**Remaining:** 28 items ⬜. Порядок: P2 (4 из 6: P2.3–P2.6) → P3 (6) → P4 (4) → P5 (6) → P6 (3) →
P7 (4). Батчи B1/B2 и два оставшихся гейта владельца (P3.1, P4.2) — `roadmap.md`.

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (правила исполнения) и §5 (**D1–D99**) ·
**ЗАМОРОЖЕНЫ: `root/naming-canon.md`, `root/document-type-axis.md`, `root/document-contour.md`,
`root/document-analyzer-contract.md`** — правятся ТОЛЬКО новой D-записью со `supersedes` ·
`findings/extraction-boundary-2026-07-29.md` · `research/01-target-architecture.md` · `roadmap.md` ·
`phases/P1.md` (`## Phase Handoff`) · `phases/P2.md` (P2.1/P2.2 Completion Notes).

**Lint (`python3 <узел>/packages/task/scripts/plan-lint.py plans/2026.07.28-EST-ARCH --baseline
HEAD`, узел — из `~/.swissknifeman/topology.json`):** **0 ERROR / 0 WARN — новых от диффа P2.2: 0**
(на итоговом дереве, после перезаписи handoff).

**Open risks:**
- **`ScreeningOutcomeHandler.php:86`** (`AiDocumentType::tryFrom($analysis->document_type)`) —
  остаётся МЯГКИМ (`tryFrom`, не `from`) НАМЕРЕННО: эта строка вне `Files` P2.2 (только строка
  вызова резолвера, ~97, была в периметре), её развязка — предмет **P2.3** («перевод
  `ScreeningOutcomeHandler::branchByType()` на реестр»). P2.3 должен решить, остаётся ли
  `tryFrom`+`null→ignored` окончательной семантикой или тоже требует громкой ошибки — НЕ считать
  автоматически закрытым фактом P2.2.
- **Три места кастуют `$analysis->document_type` инлайн** (`ExtractionOrchestrator::run()`,
  `::runWindowed()`, `RerunRangeAction::run()`) вместо одной точки на модели/репозитории (буквальное
  чтение Rule 2 P2.2) — сознательное сужение до границ `Files` item'а (Analysis-модель вне периметра);
  если P2.4/P3.5 при переезде этих классов решат централизовать кастование на `Analysis`-модели —
  это НЕ реопен P2.2, а естественное продолжение по мере переезда владения.
- **Форма кастования `AiDocumentType::from($x ?? Y::value)` вместо `$x !== null ? … : …`** — выбрана
  специально, чтобы не завести НОВУЮ ошибку PHPStan `property.notFound` на `$estimate->analysis`
  (relation-property без `@property-read` на `Estimate`, Larastan не резолвит тип вне `??`-контекста).
  Если фаза P3/иная добавит `@property-read Analysis $analysis` на `Estimate`, обе формы станут
  эквивалентны — трогать `RerunRangeAction` ради этого отдельно не нужно.
- **`root/document-type-axis.md` §5 цитирует УСТАРЕВШИЕ номера строк Filament-форм** (писался на
  `dc0815c`) — наследуется из P2.1, не переоткрывалось.
- **Предпросмотр склеенного промта ДО прогона** — требование владельца, в план сознательно НЕ
  введено (решение 2026-07-29: «делается быстро, сообщу отдельно»). P5.5 покрывает промт,
  реально УШЕДШИЙ в провайдера. Кандидат в scope P5.5 либо item P5.7 — поднимается по требованию
  владельца, не автоматически.
- Наследуются из P1: журнальный разрыв P1.5–P1.8 (D99) · дыра протокола D98 (репозиторий
  `swissknifeman`) · практика D89-исхода для гейтов-сторожей инварианта без D-записи ·
  `config/ai.php` несёт докблочные упоминания старых имён таблиц · три строки Pending Work про
  `CLAUDE.md` живут до P7.4 (D60/D64) · боевой прогон P5.2 — за владельцем (D94) · периметр
  coupling для строковых значений enum'ов — держать на P2.5 · периметр `Files` собирается не
  только от читателей колонки (D96), но и от писателей связи и от фабрик (находка P1.6).

**Workarounds/Deferred/Open questions:**
- workarounds: доказательство фальсифицируемости гейта МУТАЦИЕЙ вместо прогона на `<base>` — для
  гейтов над создаваемым артефактом (решение владельца 2026-07-29); применено в P1.1–P1.6 и P2.1,
  впредь требуется у P3.1 и P4.2 · `docker exec agelto_app` вместо `docker compose exec app` —
  интерполяция `.env` падает на `MINIO_ROOT_PASSWORD` (воспроизвелось на P2.2) · `pint --dirty`
  автофикс — точечными Edit-правками (host-запуск заблокирован permission-гейтом сессии)
- deferred: BigQuery-адаптер биллинга (D34) · HITL-правка эталона (D48) · пере-замер F1 боевым
  прогоном (D52) · остаточная дупликация `estimate:dataset:import` (D57) · перенос дрейфа в
  `CLAUDE.md` — P7.4 (D60/D64) · общий инвариант «во всём сервисном слое нет прямых обращений к
  `LlmCall`» — в план НЕ вводится (D70/D79) · переезд экранов транспорта из
  `app/Filament/Resources/Procurement/Ai/**` — вне D22 (D96) · expression-индексы по `type_profile`
  — не заводятся до 10 000 строк реестра · `resources/ai/prompts/README.md` пример front-matter без
  `document_type` · `config/ai.php` докблочные упоминания старых имён таблиц · канонизация
  D89-исхода для гейтов-сторожей инварианта · правка escape-hatch `plan-exec.md` под
  `topology.json` (D98) — репозиторий `swissknifeman`, вне этого плана · раскладка DTO сметы под
  сегмент `Analyzers\Estimate` — решает P3.5, контракт P2.1 её НЕ предписывает · централизация
  кастования `document_type` на модели `Analysis` (см. Open risks выше) — не входит в P2.2, может
  всплыть при переезде P2.4/P3.5
- open_questions: в границах плана — НЕТ. Остаются два гейта владельца впереди (P3.1, P4.2). Вне
  плана: P7.6 плана `2026.07.26-EST-TRAIN` (боевое замыкание петли тюнинга)
