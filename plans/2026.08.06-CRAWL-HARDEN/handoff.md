# HANDOFF — 2026-08-08 — after P4.4

**Next:** /task:plan-design 2026.08.06-CRAWL-HARDEN P4

| Параметр | Значение |
|:--|:--|
| Model | frontier (`gpt-5.6-sol`) |
| Thinking | high — repair archive budget control flow |
| Context | NEW SESSION — шаг-не-item |
| Суть | Добавить follow-up перед P4.5: archive budget exhaustion без продвижения дня/Failed и замена ложного network grep. |
| Команда | `/task:plan-design 2026.08.06-CRAWL-HARDEN P4` |

**Done:** P4.4 закрыт 🟠 item-коммитом `695b2524`: общий fixed-window budget на host,
активные proxy-привязки, стадийный jitter, typed exhaustion и реакции fresh/detail/document;
`PoliteThrottle` удалён.

**Remaining:** follow-up archive control-flow; затем P4.5–P4.6.

**Sources of truth:** phases/P4.md P4.4 Completion Notes · plan.md §3 Routing ·
item-коммит `695b2524` · journal.jsonl.

**Open risks:** Archive/Delta/Backlog пока превращают budget exhaustion в failed run, потому что
их дневные обёртки не получают отдельного сигнала checkpoint. Literal-grep сети красен на
baseline; реальную fail-closed защиту даёт `TestEnvironmentGuard`.

**Workarounds/Deferred/Open questions:** workarounds — fresh checkpoint безопасен, archive
остаётся на long lane до follow-up; deferred — P4.5/P4.6; open_questions — форма control-flow
контракта scanner → дневные обёртки.
