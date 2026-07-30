# HANDOFF — 2026-07-30 — after P4.1

**Next:** голая команда

```
/task:plan-design 2026.07.28-EST-ARCH P4.2
```

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — предписанная §3 Routing цена ошибки item'а (design-item с гейтом владельца) |
| Context | NEW SESSION — шаг-не-item |
| Суть | Design-гейт владельца: контракт источника расхода при отсутствии REST-биллинга Gemini (D11/D34), граница «наш трекинг ↔ биллинг», подпись источника и горизонт retention (D37), судьба обоих существующих потребителей `ai:cost-report`/`LlmSpendCard` (D35/D38) — форма замерзает для P4.3/P4.4 (батч B1) |

**Done:** фаза P1 закрыта 🟠 (4/8 🟢). Фаза P2 закрыта 🟠 (5/7 🟢, 2/7 🟠). Фаза P3 закрыта 🟠
(3/6 🟢, 3/6 🟠), проаудирована (ATTENTION, 10 находок). Фаза P4 идёт: P4.6 🟢 (`af5475d`), P4.5 🟢
(`eeb519d`, сверено `plan-close`), P4.1 🟢 (`f13dc43`) — батч B3 закрыт целиком, первый item
денежного контура закрыт.

- **P4.1 закрыт 🟢** — единый сейм `App\Services\Procurement\Ai\UsageSnapshotRecorder`: слепок
  расхода (`provider`/`model`/токены/`cost_micros`) пишется на `Analysis` во ВСЕХ восьми
  терминальных точках (оба исхода screening/extraction, оба `failed()` в job'ах, оба исхода
  `ShadowAnalysisJob`) и на `AnalysisRun` в трёх прямых терминациях `ScreeningOutcomeHandler` —
  как побочный эффект `finalizeAnalysis()`, пересчитываемый идемпотентно. Принадлежность
  назначения шагу — из `DocumentAnalyzerRegistry` (D39), теневой анализ — всегда `purpose=shadow`
  независимо от шага. `AnalysisStoreRepository`/`AnalysisRunStoreRepository::markSucceeded/
  markFailed`/`Analysis.php` НЕ тронуты (разошлось с ожидаемым `Files` item'а, но узкого метода
  `AnalysisRunStoreRepository::recordUsage()` хватило). `finalizeSuccess()` extraction получил
  `ResolvedRoute $route` — теперь пишет `provider`/`model` (раньше не писал). Полный сюит: 799
  passed/1 skipped (3253 assertions), `phpstan` 0 ошибок, baseline не тронут, `pint --test`
  зелёный, миграции не тронуты. Известная находка вне Scope: `FormatBenchRunner`/
  `ExtractionEvalRunner`/`ScreeningEvalRunner` несут собственные catch-фолбэк терминаторы прогона
  мимо сейма (третий харнесс — бенч/eval, не D91-контуры) — см. Pending Work P4.1.
- Known Deviations P4.1 — оба процессные: Task Contract pre-mutation прогнан ретроактивно (после
  начала мутации, а не до — Routing-гейт при этом сверен вручную ДО работы и совпал); Validation 7
  исполнен по канонической форме D115 (`pint --test <Files>`), не по буквальному тексту item'а.

**Remaining:** 15 items ⬜. Порядок: P4.2 (гейт владельца) → B1 (P4.3+P4.4) → P5 ×6 → P6 ×3 →
P7 ×4 (B2 = P7.1+P7.2). Гейт владельца впереди один — P4.2. Батчи: **B1** (`plan-exec`), **B2**
(`plan-run`) — `roadmap.md`. Батчи **B3** (P4.5+P4.6) отработан полностью.

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (правило формы шаблонов — D121), §3
Routing, §5 (D1–D121) · ЗАМОРОЖЕНЫ: `root/naming-canon.md`, `root/document-type-axis.md`,
`root/document-contour.md`, `root/document-analyzer-contract.md`, `root/service-layer-map.md` ·
`phases/P4.md` (P4.1 🟢 — Completion Notes/Known Deviations/Pending Work; P4.5 🟢; P4.6 🟢) ·
`findings/usage-accounting-2026-07-29.md` · `research/05-task-contract-acceptance-defect.md` ·
`roadmap.md` · `open-questions.md` (внутри плана открытых нет; OQ-14 — вне плана).

**Open risks:**
- **Дефект компилятора Task Contract жив** (OQ-14, `open-questions.md`): правило §2/D121 — обход,
  а не решение. Реопен-кандидат в пакет `swissknifeman/task`, решение владельца; этот план не ждёт.
- **`verify-citations.py` на всём плане даёт 263 находки «файл не найден»** — систематический баг
  резолва путей самого инструмента; не относится к диффу P4.1/P4.5. Разобрать до корня и/или
  завести реопен-кандидат в `swissknifeman` — вне этого захода.
- **Третий харнесс (`FormatBenchRunner`/`ExtractionEvalRunner`/`ScreeningEvalRunner`) не переведён
  на `UsageSnapshotRecorder`** — их catch-фолбэк терминаторы прогона (редкая ветка «оркестратор
  бросил на середине») пишут статус прогона без слепка; вне `Files` P4.1, находка названа в
  Pending Work, отдельного item плана не заводит.
- **`plan-lint --baseline HEAD` даёт 1 ERROR, K=0 от диффа P4.1** — строка `write-site:` item'а P4.5
  обёрнута `**…**`, линтер матчит без обёртки (`plan_lint.py:370`); разбор до корня и правка (если
  нужна) — вне Scope P4.1, P4.5 закрыт прошлой сессией — см. Pending Work P4.1 п.2.
- **A4 аудита P3 — исполнители пропускают построчный отчёт по Validation** (наследуется).
- **Тексты Validation items P5–P7 цитируют `pint --dirty --test`** — форма отменена D115.
- **`app/Pulse/Cards/LlmSpendCard.php:17`** — мёртвый FQN ещё жив; закрывает P4.2 (Validation 6а).
- **`CLAUDE.md` несёт то же ложное утверждение про путь модели промта**, что чинил P4.5 в
  `.ai/guidelines/architecture.md` — закрывает P7.4 (D60/D64).
- **Посторонние untracked-пути в дереве** — `.swissknife/`, `estimates/gemini-analys.md`: не входят
  ни в один `Files`, не коммитить, не удалять (§6).
- Наследуются из P1/P3: журнальный разрыв P1.5–P1.8 (D99) · дыра протокола D98 · боевой прогон P5.2 —
  за владельцем (D94).

**Workarounds/Deferred/Open questions:**
- workarounds: **правило формы §2/D121** — обход дефекта компилятора Task Contract (не улучшение
  плана): снять, когда пакет `swissknifeman/task` починят.
- deferred: перевод третьего харнесса (bench/eval) на `UsageSnapshotRecorder` (Pending Work P4.1);
  выравнивание литерального текста гейтов стиля в фазах P5–P7 (D115); ревизия имён
  `ai:storage:lifecycle`/`ai:tools:doctor` — P7.3/P7.4; сквозной `plan-design finish` перед уходом
  фаз P5–P7 в исполнение; разбор 263 находок `verify-citations.py` до корня.
- open_questions: внутри плана открытых нет. Вне плана — **OQ-14** (дефекты компилятора Task
  Contract, реопен-кандидат в `swissknifeman/task`, решение владельца). Гейт владельца впереди
  один — P4.2.
