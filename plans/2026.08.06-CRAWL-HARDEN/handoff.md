# HANDOFF — 2026-08-08 — after P4.1

**Next:** design-item: task:plan-design 2026.08.06-CRAWL-HARDEN P4.1

| Параметр | Значение |
|:--|:--|
| Model | frontier (`gpt-5.6-sol`) |
| Thinking | high — выделить follow-up для validation |
| Context | NEW SESSION — шаг-не-item |
| Суть | Спроектировать отдельный фикс трёх красных общих validation-гейтов P4.1. |
| Команда | `$ task:plan-design 2026.08.06-CRAWL-HARDEN P4.1` |

**Done:** P4.1 закрыт 🟠. `CrawlSettingsResolver` объединяет профиль, площадку и config; четыре
HTTP-места больше не читают legacy `crawler.http.*`. Item-коммит: `5ab4d403`.

**Remaining:** P4.2–P4.6; переход `PoliteThrottle` и `crawler.throttle.*` остаётся P4.4.

**Sources of truth:** `plan.md` D118 и §3 Routing · `phases/P4.md` P4.1 Completion Notes ·
`findings/P4-settings-readers-2026-08-08.md` · `journal.jsonl`.

**Open risks:** общий Pest красный на предсуществующем CoreKnowsNoDocumentTypeTest; `migrate:fresh
--seed` падает в EisPlatformSeeder на обязательном Registry.uuid; network grep ловит старые URL в
HTTP-fake тестах.

**Workarounds/Deferred/Open questions:** `PoliteThrottle` и `crawler.throttle.*` — переходный долг
P4.4. Для трёх validation-красных нужен отдельный follow-up, а не ослабление P4.1.
