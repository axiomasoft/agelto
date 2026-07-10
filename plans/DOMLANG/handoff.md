# HANDOFF — 2026-07-10 — after P1.2

**Next:** P1.3 — Pre-mortem схемных фаз P3/P4 (терминальный item фазы P1)

| Параметр | Значение |
|:--|:--|
| Model | P1.3: opus/xhigh (pre-mortem несущих схемных решений требует рассуждения) |
| Thinking | enabled |
| Context | fresh; холодный старт: plan.md (§5 Decision Log) + P1.md (Phase Context + item P1.3) + findings/P1-audit-verification.md |
| Суть | Pre-mortem по Klein для P3 (ренейм колонок, 341 PHP-файл, 99 тестов) и P4 (provenance). Независимая запись причин провала → кластеризация → mitigation с owner'ом и ссылкой на item → в D-лог plan.md. |

````
Launch-block P1.3:
1. Прочитать РОВНО в порядке: plan.md (§5 Decision Log, §2 Execution Rules) → handoff.md (этот) →
   phases/P1.md (Phase Context + item P1.3 целиком) → findings/P1-audit-verification.md →
   Required Reads item'а: phases/P3.md, phases/P4.md, docs-private/risk-register.md,
   plans/EST-AI/phases/P1.md (Completion Notes про санацию схемы).
2. Предусловие: P1.2 терминален (🟢 Done ✅), P1.3 = ⬜ → выставить 🟡 In progress в P1.md
   (item-header + Phase Status).
3. Протокол Klein: СНАЧАЛА независимо записать причины провала (не обсуждая), ЗАТЕМ кластеризовать,
   ЗАТЕМ mitigation + owner на кластер. Каждая mitigation — ДЕЙСТВИЕ в конкретном item'е, не намерение.
4. Обязательны к разбору 5 сценариев (Implementation Rules P1.3): (1) миграции ушли в main до правки;
   (2) ренейм колонки не догнал Filament/factory/morph map; (3) etp_number заведён, но никто не
   заполняет — мёртвая колонка; (4) provenance материализован без потребителя (наруш. ADR-0008);
   (5) source_stage переименован, а prc_status_mappings.source_status — нет, связка развалилась.
5. Validation: каждый из 5 сценариев → mitigation-строка в D-логе с owner+item ЛИБО запись в
   open-questions.md со статусом; §6 Update Log содержит строку про pre-mortem; 0 маркеров
   [NEEDS CLARIFICATION] в P3.md и P4.md.
6. Закрыть item, синхронизировать Phase Status + Status Board, перезаписать handoff.md.
   Item P1.3 терминальный для P1 → на его закрытии заполнить Phase Handoff в P1.md и закрыть фазу.
7. Один коммит: файлы плана, сообщение 'docs(DOMLANG): P1.3 — pre-mortem P3/P4'.
````

**Done (P1.2):**
- ✅ Три ADR приняты: `docs/03_Dev/adr/0006-bounded-contexts-as-folders.md` (отклонён namespace-ренейм, D2),
  `0007-ocds-as-exchange-layer.md` (OCDS = exchange layer, D3), `0008-thin-provenance-slice.md` (тонкий
  срез provenance, D4). Каждый: Alternatives дословно из D-лога + «Триггер пересмотра» проверяемым событием.
- ✅ `docs/03_Dev/architecture.md` — новая секция «Архитектурные решения (ADR)» с индексом 0001–0008.
- ✅ Изоморфизм `prc_events` ≈ OCDS `releases` (immutable) верифицирован кодом ДО написания 0007:
  append-only партиционированная миграция + `storeEvent()` = чистый `create()`. Эскалация не потребовалась.
- ✅ Validation: 4/4 passed (3 файла; architecture.md перечисляет 0006–0008; «Триггер пересмотра» ×1 каждый;
  RAG:✅ каждый ≥1).

**Remaining:** 20 item в P1–P9. Фаза P1 — 2/3 (остался терминальный P1.3, на нём закрывается P1). P2–P9 — полный объём.

**Sources of truth:**
- `plans/DOMLANG/findings/P1-actual-names.md` ← канон имён для P2–P5 (НЕ спеки!)
- `plans/DOMLANG/plan.md` (§5 Decision Log D1–D10, §6 Update Log)
- `database/migrations/**` ← источник истины на уровне таблиц
- `docs/03_Dev/adr/0001-0008` ← несущие решения (0006/0007/0008 добавлены в P1.2)
- `docs-private/risk-register.md` ← риски, которые разбирает P1.3

**Open risks:**
- До закрытия P6 спеки `docs/specs/v2/**` — **ненадёжный источник** (166 мёртвых префиксов). Используй P1-actual-names.md.
- P3 правит published миграции на месте. Hard-гейт P3.1: `git log origin/main -- database/migrations/` должен вернуть empty.
- `prc_status_mappings` не сидируется (P2.1).
- `PipelineDispatcher` не мутирует статус документа (P5.1).
- Gemini 2.5 EOL: 2026-10-16 (вне плана, но скоро).

**Workarounds/Deferred/Open questions:**
- Workarounds: нет.
- Deferred: Q3 (`etp_number`: сразу или после источника) — решается в pre-mortem P1.3 (сценарий 3).
- Open questions: `open-questions.md` (4 записи, 1 требует решения owner'а).
