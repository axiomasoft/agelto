# HANDOFF — 2026-08-08 — after P4.7

**Next:** ЗАПУСК ВРУЧНУЮ: implementation/high

| Параметр | Значение |
|:--|:--|
| Model | implementation (`gpt-5.6-terra`) |
| Thinking | high — трёхуровневые настройки и профиль |
| Context | continue (reset the session context) — ручной item |
| Суть | P4.1 вводит profile/platform/global settings resolver для параметров обхода. |
| Команда | — |

```text
select session model class implementation
select session effort high
$ task:plan-run 2026.08.06-CRAWL-HARDEN P4.1
```

**Done:** P4.7 закрыт 🟠. `prc_crawlers.execution_lane` хранит `standard|long`; `CrawlRunJob`
атомарно получает route `connection+queue` из profile lane и сохраняет lane при continuation.
Стандартный контур остался `redis/crawl` (300/360), long — `redis_crawl_long/crawl_long`
(3600/3900) с grace 4200; сидовый архив 2025 — long, fresh — standard. Кодовый коммит:
`a1bbd1e5 feat(crawler): добавить длинный execution lane (P4.7)`.

**Remaining:** P4.1–P4.6; фаза P4 остаётся 🟡 In progress (1/7).

**Sources of truth:** `plan.md` D117 и §3 Routing · `phases/P4.md` P4.7 Completion Notes ·
`research/12-p4.7-long-execution-lane.md` · `journal.jsonl`.

**Open risks:** Универсальный checkpoint-requeue archive/delta/backlog по секундомеру остаётся
сознательным долгом D117. Полный Pest: 18 pre-existing failed, Δfailed=0 от baseline `8728723e`;
P4.7 не добавил новых красных. Если production-like archive CSV устойчиво >3600s, не повышать
timeout молча: зафиксировать разбивку HTTP/throttle/DB и запросить новую D-запись.

**Workarounds/Deferred/Open questions:** Workaround временного архива — конечный long lane, не
секундный checkpoint. Deferred — универсальный checkpoint-requeue archive/delta/backlog. P4.7
имеет material scope deviation: два console-теста вне Files переключены на sync driver в testing,
поскольку job теперь явно выбирает Redis connection; поэтому item закрыт 🟠.
