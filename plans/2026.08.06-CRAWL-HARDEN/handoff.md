# HANDOFF — 2026-08-08 — after P4.3

**Next:** ЗАПУСК ВРУЧНУЮ: implementation/high

| Параметр | Значение |
|:--|:--|
| Model | implementation |
| Thinking | high — design shared request budget semantics |
| Context | continue (reset the session context) — ручной item |
| Суть | Выполнить P4.4: ввести общий бюджет запросов к площадке. |
| Команда | — |

~~~text
/task:plan-run 2026.08.06-CRAWL-HARDEN P4.4
~~~

**Done:** P4.3 закрыт `88912091`: auto-disable, health-витрина и явное возвращение узла оператором.

**Remaining:** P4.4–P4.6; отдельный fix-item для 12 предсуществующих Pint-нарушений.

**Sources of truth:** phases/P4.md P4.2–P4.3 · plan.md §3 Routing · journal.jsonl.

**Open risks:** Полный Pint остался красным на предсуществующих 12 style issues при P4.8; P4.2 Pint
зелёный. P4.3 не должен расширять scope на этот cleanup.

**Workarounds/Deferred/Open questions:** workarounds — PoliteThrottle и crawler.throttle.* до
P4.4; deferred — Pint cleanup; open_questions — —.
