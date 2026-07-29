# HANDOFF — 2026-07-29 — after P2.3

**Next:** «ЗАПУСК ВРУЧНУЮ: implementation/high» — P2.4 «Расщепление слоя `Extraction`: машинерия ↔
предметка». `Exec = manual` по §3 Routing (массовое перемещение 17 единиц по границе D28 в файлы,
где живут checkpoint-requeue/lease/`ShouldBeUniqueUntilProcessing`: решений нет, но молча
потерянный инвариант обнаруживается только зависшим документом). `roadmap.md` — solo, не батч.
`/task:plan-exec` этот item отклонит гейтом п. 2 (`ROUTING-BLOCKED`) — не пытаться.

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | P2.4 — 16 сметных единиц + `EstimateExtractionJob` уезжают в `**\Analyzers\Estimate\**` по конвенции контракта P2.1, 5 единиц машинерии остаются; дифф перемещённых файлов — только `namespace`/`use`; baseline регенерируется под гейтом трёх величин (D58) |

```
/model opus
/effort high
/task:plan-run 2026.07.28-EST-ARCH P2.4
```

**Done:** фаза P1 закрыта 🟠 (4/8 🟢, детали — `phases/P1.md` → `## Phase Handoff`). **P2.1 закрыт
🟢** (`fcc0259` + bookkeeping): контракт `DocumentAnalyzer` заморожен гейтом владельца. **P2.2
закрыт 🟢** (`c485dab` + bookkeeping): маршрутизация типизирована на
`AiDocumentType|DocumentTypeScope`, литералы `'estimate'`/`'any'` сняты в 12 call-site'ах.
**P2.3 закрыт 🟢 (`2deb5c1` item + bookkeeping):** заведены `DocumentAnalyzer` (пять методов
контракта §1.2), `DocumentAnalyzerRegistry` (синглтон, сборка из `config('ai.analyzers')`,
`config:cache`-безопасно), DTO сводки `AnalysisSummary`/`SummaryMetric` и первая реализация
`App\Services\Procurement\Analyzers\Estimate\EstimateAnalyzer`. `ScreeningOutcomeHandler` и
`RerunAnalysisAction` не содержат имени `Estimate` вовсе (греп-гейты пусты); `outcome` события
выводится из `AiDocumentType->value` с сохранением прежнего значения `estimate`; рубильник
`ai.extraction.enabled` остался ядерным. +14 тестов, фальсифицируемость трёх новых гейтов
доказана мутацией. Полные факты — `phases/P2.md` → P2.3 Completion Notes.

**Remaining:** 27 items ⬜. Порядок: P2 (3 из 6: P2.4–P2.6) → P3 (6) → P4 (4) → P5 (6) → P6 (3) →
P7 (4). Батчи B1/B2 и два оставшихся гейта владельца (P3.1, P4.2) — `roadmap.md`.

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (правила исполнения) и §5 (**D1–D99**) ·
**ЗАМОРОЖЕНЫ: `root/naming-canon.md`, `root/document-type-axis.md`, `root/document-contour.md`,
`root/document-analyzer-contract.md`** — правятся ТОЛЬКО новой D-записью со `supersedes` ·
`findings/extraction-boundary-2026-07-29.md` · `research/01-target-architecture.md` · `roadmap.md` ·
`phases/P1.md` (`## Phase Handoff`) · `phases/P2.md` (P2.1–P2.3 Completion Notes).

**Lint (`python3 <узел>/packages/task/scripts/plan-lint.py plans/2026.07.28-EST-ARCH --baseline
HEAD`, узел — из `~/.swissknifeman/topology.json`):** **0 ERROR / 0 WARN — новых от диффа P2.3: 0**
(на итоговом дереве, после перезаписи handoff).

**Open risks:**
- **Ось типа документа у анализа ИЗВЛЕЧЕНИЯ пуста в боевом контуре** (`AnalysisStoreRepository::
  firstOrCreate()` `document_type` не пишет; единственный писатель колонки — `ScreeningProcessor:222`).
  P2.3 закрыл это цепочкой «свой `document_type` → `document_type` активного анализа скрининга той
  же версии» ВНУТРИ `RerunAnalysisAction`. Централизация каста/резолва на модели `Analysis` (та же
  тема, что унаследованный риск P2.2 про три инлайн-каста в `ExtractionOrchestrator`/
  `RerunRangeAction`) остаётся кандидатом на P2.4/P3.5 — но `RerunAnalysisAction` в ядре ОСТАЁТСЯ
  (P2.4 Implementation Rule 3), поэтому переезд его резолва туда не автоматический.
- **Тест `outcome` события не фальсифицируем против состояния до P2.3** (старый литерал давал те же
  две строки) — полное поведенческое доказательство «значение выведено из типа» достижимо только на
  ВТОРОМ типе с извлечением, то есть в P2.6. Не считать закрытым фактом P2.3.
- **Четыре `*For()`-метода реестра (контракт §5.2) и инвариант сборки §5.4 п. 2 ещё не написаны** —
  отложены в P2.5 вместе с расщеплением enum'ов (до него множества «ядерных» значений в коде нет).
  Их четыре потребителя (`PromptSeeder`, `AiRoutingSeeder`, `PromptForm`, `AiRouteForm`) названы
  Scope Included P2.5. Если P2.5 не реализует их — приёмка §7 контракта не закрыта ничем.
- **Форма Validation 5 «`config:cache` + `artisan test`» не имеет зелёного исхода в этом проекте**
  (кэш конфига замораживает dev-БД → `TestEnvironmentGuard` штатно прерывает прогон). P2.3 прогнал
  эквивалент с env-префиксом на шаге `config:cache`; форма гейта в ТЗ — кандидат на фикс
  `plan-design`, и тот же дефект формы стоит проверить у будущих items, копирующих эту строку.
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
  гейтов над создаваемым артефактом (решение владельца 2026-07-29); применено в P1.1–P1.6, P2.1 и
  P2.3, впредь требуется у P3.1 и P4.2 · `docker exec agelto_app` вместо `docker compose exec app` —
  интерполяция `.env` падает на `MINIO_ROOT_PASSWORD` (воспроизвелось на P2.2 и P2.3) · env-префикс
  (`-e DB_DATABASE=agelto_test …`) на шаге `config:cache` — иначе кэш замораживает dev-БД и страж
  изоляции прерывает прогон (P2.3) · `pint --dirty` автофикс — точечными Edit-правками (host-запуск
  заблокирован permission-гейтом сессии)
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
  кастования/резолва `document_type` на модели `Analysis` (см. Open risks) — кандидат P2.4/P3.5 ·
  `AnalysisSummary::$detailRoute` = `null` до появления предметного экрана сметы (P5)
- open_questions: в границах плана — НЕТ. Остаются два гейта владельца впереди (P3.1, P4.2). Вне
  плана: P7.6 плана `2026.07.26-EST-TRAIN` (боевое замыкание петли тюнинга)
