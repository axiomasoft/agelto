# HANDOFF — 2026-07-11 — after P9.3

**Next:** план закрыт (и архивирован). Все 9 фаз DOMLANG терминальны (`🟢`×6 — P2/P3/P5/P6/P7/P8;
`🟠`×3 — P1/P4/P9 с задокументированными Known Deviations/D38). `Meta Status` → `🟠 Done with
deviations`, `plans/ACTIVE.md` = `—`. Дальнейшая работа с этим планом — вне exec/design/close-
протокола; следующий план выбирает владелец.

**Миграция root/ → docs (чек-лист):** каталога `plans/DOMLANG/root/` не было — долгоживущий
материал плана изначально писался напрямую в `docs/03_Dev/**` каждым item'ом (не в `root/` с
последующим переносом): `docs/03_Dev/state-machines.md` (P8.1), `docs/03_Dev/entity-lifecycle-map.md`
(P8.2), `docs/03_Dev/ocds-mapping.md` (P7.2), `docs/03_Dev/adr/0006-0009-*.md` (P1.2/P5.1),
`.ai/guidelines/glossary.md` + `docs/02_Workflow/glossary.md` (P7.1). Шаг 3 конвейера — **пропущен
как N/A** (материал уже на месте, второй перенос не требовался). Ссылки на план из этих 9 файлов
обновлены `plans/DOMLANG` → `plans/archive/DOMLANG` (см. коммит `docs(plan): миграция root/ DOMLANG
→ docs`). **Остаётся в архиве** (не мигрирует в docs — по правилу конвейера): `findings/*.md` (10
файлов), `open-questions.md`, `workflow*.js` (per-phase runners), `plan.md`/`handoff.md`/`phases/*.md`.

**Done:** P1–P8 терминальны. **P9 терминальна (2/3 🟢 + 1 ⛔ → 🟠 Done with deviations):**
- **P9.1 🟢** — milestone adversarial review (харнесс `estai-milestone-review.js`): gate=PASS,
  0 HIGH/CRITICAL → ATTENTION, 0 reopen. `findings/P9-milestone-review.md`.
- **P9.2 🟢** — plan-audit P1–P8 (fan-out 8 агентов): роллап ATTENTION, 0 Blocker/RED → 0 reopen.
- **P9.3 ⛔ Skipped by decision** (D38) — ultra опционален, не обязательный гейт.

**Remaining:** вынесено владельцу, не блокирует архивацию —
- **Опционально, on-demand:** `/code-review ultra` для ветки `feat/v2-scaffold` перед merge —
  внешний облачный проход поверх агентских P9.1/P9.2; при Blocker → reopen соответствующей фазы
  (D37, после разархивации через `plan-audit`).
- 2 Major процессных (P9.2): routing-адхеренс P6.1 (документирование), P7 review-лейн (запись о
  прогоне) — контент переверифицирован корректным дважды, не корректностный долг.
- 3 PROMOTE-CANDIDATE для `plan-protocol` (per-leg routing records; plan-design коммитит отдельно;
  DoR Scope↔Validation-гейт).
- Мисатрибуции Update Log (P1.1/P2.1/P6.1) в архивном `plan.md`.
- F1 (MED) `ShadowAnalysisJob::onQueue('ai_shadow')` без Horizon-супервайзера — pre-existing
  EST-AI, вне scope DOMLANG.

**Sources of truth:**
- `plans/archive/DOMLANG/plan.md` §4 Status Board (все фазы терминальны), §5 D1–D38, §6 Update Log.
- `plans/archive/DOMLANG/phases/P9.md` — P9.1/P9.2 🟢 + P9.3 ⛔ Completion Notes; Phase Handoff.
- `plans/archive/DOMLANG/findings/P9-milestone-review.md` — вердикт P9.1.
- `plans/archive/DOMLANG/phases/P1.md`…`P8.md` — append-блоки `## Audit Pn` (P9.2).
- Живые доки, ссылающиеся на этот план (пути обновлены на `plans/archive/DOMLANG`):
  `.ai/guidelines/glossary.md`, `docs-private/{crawler-gotchas,risk-register}.md`,
  `docs/03_Dev/{state-machines,entity-lifecycle-map}.md`, `docs/03_Dev/adr/000{6,7,8,9}-*.md`.

**Open risks:**
- **2 Major (процессные, вынесены владельцу, из P9.2):** routing-адхеренс P6.1 (haiku/low пинн vs
  Update Log sonnet, undocumented) и P7 review-лейн opus/high без записи о прогоне. Корректностного
  дефекта нет → не reopen.
- **3 PROMOTE-CANDIDATE для plan-protocol** (вынесены владельцу).
- **F1 (MED, owner-disposition, из P9.1)** — вне scope DOMLANG.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: коррекция Update Log в архивном `plan.md`; решение по review-лейну P7; 3
  PROMOTE-CANDIDATE — владельцу/plan-protocol; опциональный on-demand ultra перед merge; F1
  `ai_shadow` — вне DOMLANG.
- open_questions: `open-questions.md` Q1–Q8 закрыты фактом архивации плана — новых не порождает.
