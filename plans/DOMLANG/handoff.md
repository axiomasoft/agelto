# HANDOFF — 2026-07-11 — after P9.1

**Next:** P9.1 закрыт (🟢, вердикт **ATTENTION**: gate=PASS, 0 HIGH/CRITICAL, reopen-тикетов нет).
Фаза P9 — 1/3, детализирована. Следующий исполняемый item — **P9.2** (`plan-audit` по каждой
закрытой фазе P1–P8: сверка 🟢/🟠 с git-фактами, no-op-детект, scope drift, routing-adherence;
append-блоки `## Audit Pn`). Порядок: P9.2 → P9.3 (owner).

| Параметр | Значение |
|:--|:--|
| Model | opus/xhigh |
| Thinking | xhigh — plan-integrity аудит (Routing §3: `P9.2 \| opus/xhigh`); НЕ sonnet/medium-дефолт |
| Context | fresh session — handoff свежий, рекомендован `/clear` |
| Суть | P9.2: профиль `plan-audit` (`commands/plan-audit.md` пакета task) по P1–P8 — суди по git/коду, воспроизводи Validation каждого item'а; в конец каждого `phases/Pn.md` дописать `## Audit Pn — <дата>` (единственная разрешённая мутация); роллап-вердикт GREEN/ATTENTION/RED; Blocker/Major → reopen-тикет по D37, не чинить на месте |

```
/task:plan-exec DOMLANG P9.2
```

**Done:** P1–P8 терминальны (Update Log `plan.md` §6). **P9.1 🟢 Done** (2026-07-11): прогнан
milestone-харнесс `estai-milestone-review.js` (scriptPath+args, D36) по P1–P8, `gate:true`, 3 ревьюера
— 19 агентов, 0 ошибок; fan-out 15 → verify 14/15 → synthesis `verdict:PASS, blocking_findings:[]`.
Вердикт P9.1 = **ATTENTION** (PASS + непустые surviving, максимум MED). `findings/P9-milestone-review.md`
создан. F1 (MED, `ai_shadow` без Horizon-супервайзера) независимо CONFIRMED, но вне scope DOMLANG
(pre-existing EST-AI) → owner-disposition, не reopen. Прикладной код не трогался (review-only).
Дизайн-дифф `/task:plan-design DOMLANG P9` (был незакоммичен) вошёл в коммит закрытия P9.1.

**Remaining:** P9 (1/3, детализирована) — исполнение:
- **P9.2** (opus/xhigh) — `plan-audit` по P1–P8, append-блоки `## Audit Pn`, роллап-вердикт.
- **P9.3** (owner) — `/code-review ultra` запускает **владелец** (D9); агент готовит вход (чистое
  закоммиченное дерево + owner-инструкция) и СТОПает на 🟡; закрытие 🟢 только после подтверждённого
  прогона + разбора находок. `Escalation Needed: yes`.

**Sources of truth:**
- `plans/DOMLANG/plan.md` §3 Routing (P9.2 → opus/xhigh), §4 Status Board (P9 1/3 🟡), §5 D9/D36/D37,
  §6 Update Log.
- `plans/DOMLANG/phases/P9.md` — P9.1 🟢 (Completion Notes заполнены), P9.2/P9.3 ⬜; Phase Handoff
  пуст (ждёт закрытия фазы).
- `plans/DOMLANG/findings/P9-milestone-review.md` — вердикт ATTENTION + 6 уникальных находок +
  реконсиляция + reopen-маршрутизация (пусто: 0 HIGH/CRITICAL).
- `commands/plan-audit.md` (пакет task) — чеклист профиля P9.2:
  `/home/vostrikov/projects/packages/swissknifeman/packages/task/commands/plan-audit.md`.

**Open risks:**
- **F1 (MED, owner-disposition):** `ShadowAnalysisJob::onQueue('ai_shadow')` + `ShadowDispatch` пушат
  в очередь без супервайзера в `config/horizon.php` (есть только `default`/`doc_prepare`/`ai_screening`/
  `ai_extraction`) → shadow-job'ы молча копятся в Redis. Вне scope DOMLANG (pre-existing EST-AI, ни одна
  фаза P1–P8 не трогала). Судьба (супервайзер `ai_shadow` ИЛИ config-honesty-гейт shadow-route) — за
  владельцем, наравне с deferred D26.
- **Untracked plan-tooling residue:** `plans/DOMLANG/workflow-P6.js` (и, если появятся, `workflow-P7.js`/
  `P8.js`) — runner'ы прошлых фаз. НЕ code-defect: дифф приложения от них не зависит. Судьба (commit/
  gitignore) — за владельцем на plan-close, вне Scope P9 (P9.2 фиксирует как known plan-hygiene).
- Остаток дедпрефикс-грепа в коде/тестах (F2 `CrawlerRunManager.php:16`, F3 test-descriptions) —
  pre-existing residue вне scope всех фаз (P3=ренейм колонок, P6=docs-only D29); LOW-follow-up, не дефект.

**Workarounds/Deferred/Open questions:**
- workarounds: запуск харнесса P9.1 — только `scriptPath` + args-объект, НЕ named-workflow (D36;
  named доставляет args строкой). Дизайн-дифф P9 был незакоммичен предыдущим шагом — сфолжен в коммит
  закрытия P9.1 (traceability-нота в Completion Notes).
- deferred: unique-индексы под естественные ключи `prc_financing`/`prc_items`/`prc_protocols`/
  `prc_awards` (класс D26, вне DOMLANG); F1 `ai_shadow`-очередь (вне DOMLANG, owner).
- open_questions: `open-questions.md` Q1–Q8 без изменений; P9 — review-фаза, новых не порождает.
