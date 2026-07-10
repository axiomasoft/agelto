# HANDOFF — 2026-07-10 — after P6.1

**Done:** `P6.1` `🟢 Done` — карта вхождений пересобрана под D32. Категория `N/A` введена: 14 вхождений 4 токенов (3 живые очереди Horizon `doc_prepare`/`ai_screening`/`ai_extraction`, 1 гипотетика ADR-0004 `ai_extractions`) классифицированы как non-table literal, входят в residue-set гейта P6.2 наравне с KEEP-v1. Все валидации Implementation Rules пройдены (grep-count=208, 0 маркеров, R/K/N верны).

**Next:** `P6.2` `⬜ Not started` — применить карту из P6.1.

| Параметр | Значение |
|:--|:--|
| Model | sonnet/medium (exec штатный; recon-item — haiku/low по §3, но переисполнение поверх готовой карты дешевле сверить sonnet'ом) |
| Thinking | medium — механическая пересборка карты по frozen-правилу, судьбоносная классификация уже заморожена (D32) |
| Context | fresh session — рекомендован /clear |
| Суть | Пересобрать карту с категорией N/A, снять маркеры, проверить R/K/N + residue-set, закрыть P6.1 |

```
/task:plan-exec DOMLANG P6.1
```

Либо фазой целиком с код-ревью-гейтом (запрос владельца):

```
Workflow({ scriptPath: 'plans/DOMLANG/workflow-P6.js' })
```

**Done:** P1 терминальна (3/3: 2🟢 + 1🟠). P2 (2/2 🟢). P3 (3/3 🟢). P4 (1/2 🟠 — `P4.2` ⛔).
P5 (4/4 🟢). **Эскалация P6.1 разрешена** (`🔴 Blocked` → `⬜ Not started`, D32): категория `N/A`
введена, G-under P6.2 переформулирован под residue-set (KEEP-v1 ∪ N/A). Карта `P6-drift-map.md`
существует с прошлого прогона (207/221 строк верны, 14 помечены NC — их exec переклассифицирует
в N/A). Добавлен per-phase runner `plans/DOMLANG/workflow-P6.js` с код-ревью-гейтом.

**Remaining:** P6.1 → `/task:plan-exec` (или `workflow-P6.js`). P6.2 стартует после закрытия P6.1.
P7 (глоссарий v2 + OCDS mapping), P8 (lifecycle-map/FSM), P9 (финальный cross-phase review) — скелеты.

**Sources of truth:**
- `plans/DOMLANG/plan.md` §5 **D32** (категория N/A + переформулировка G-under), D30 (базовое правило),
  D29 (scope), D31 (долг `:159`).
- `plans/DOMLANG/phases/P6.md` — P6.1/P6.2 обновлены под D32; Phase Context (вторая ловушка — non-table).
- `plans/DOMLANG/findings/P6-drift-map.md` — карта прошлого прогона (§0 находка, §1 префиксы, §2 колонки,
  §3 gate-числа [NC→N в переисполнении], §4 реконсиляция P1 §5, §5 arrow-rule).
- `plans/DOMLANG/workflow-P6.js` — per-phase runner: recon → item-review → edit → phase-review fan (gate-verify + doc-conformance).

**Open risks:**
- Гейт G-under P6.2 теперь ждёт остаток РОВНО = K(28)+N(14) = 42 строки префиксов. Остаток вне
  residue-set → незатёртый дрейф (доправить, не расширять множество); пропажа строки residue-set →
  затёрт v1-ref или queue-имя (откатить правку). Двусторонний контракт.
- Категория N/A frozen ровно на 4 токенах (D32). Новый non-table токен вне списка → эскалация,
  не самостоятельное расширение N/A.

**Workarounds/Deferred/Open questions:**
- Deferred: `docs/03_Dev/crawling-pipeline.md` §8 «исходная формулировка D17» — вне Scope P6.
- Deferred (из P5): unique-индексы под естественные ключи `prc_financing`/`prc_items`/
  `prc_protocols`/`prc_awards` (класс D26, вне DOMLANG).
- Open questions (`open-questions.md`): Q7/Q8 — `Exploration`, без изменений. Эскалация P6.1
  разрешена через plan-design (D32), не через `open-questions.md`.
