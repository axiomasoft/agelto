# Source synthesis — production parsing audit

Дата: 2026-09-10

## Входы

- `PARSING_PRODUCTION_AUDIT.md` — первичный production-readiness аудит текущего дерева.
- `PARSING_PRODUCTION_ACCEPTANCE.md` — независимая RED-приёмка и два новых дефекта NEW-1/NEW-2.
- `plans/archive/2026.08.06-CRAWL-HARDEN/{plan.md,status.md,handoff.md}` — историческая
  архитектурная основа и доказательство закрытия предыдущего плана с отклонениями.
- `docs/02_Workflow/{crawling-and-discovery,document-and-estimate-pipeline}.md` и
  `docs/03_Dev/{architecture,database}.md` — живой проектный канон.

## Несущие выводы

1. Архитектурная замена не требуется; defects локальны текущим PostgreSQL/Horizon contracts.
   RAG:— (repo-grounded: `PARSING_PRODUCTION_AUDIT.md:5-17`).
2. A1–A9, B1, B3 не закрыты; B2 не доказан; NEW-1/NEW-2 расширяют launch-blocking baseline.
   RAG:— (repo-grounded: `PARSING_PRODUCTION_ACCEPTANCE.md:29-44,279-321`).
3. A8 воспроизведён 13 падениями на fresh schema; это первый dependency плана.
   RAG:— (repo-grounded: `PARSING_PRODUCTION_ACCEPTANCE.md:219-238`).
4. A9 требует и scheduler overlap guard, и execution lease: уникальная run-строка не равна
   единственному исполнителю. RAG:— (repo-grounded: `PARSING_PRODUCTION_ACCEPTANCE.md:239-257`).
5. Removals и current-set reconciliation допустимы только по complete snapshot; unknown/partial
   parse не равен пустому набору. RAG:— (repo-grounded:
   `PARSING_PRODUCTION_ACCEPTANCE.md:109-137,258-277`).
6. Queue retry owner должен быть один; прямой `handle()` не доказывает Horizon timeout/release
   semantics. RAG:✅ 2026-09-10 — ссылки Laravel Horizon/queues сохранены в
   `PARSING_PRODUCTION_AUDIT.md` и `PARSING_PRODUCTION_ACCEPTANCE.md`.
7. Публичная гарантия immutable bytes для одного ЕИС `uid` не найдена. Это unknown, а не
   доказательство mutable/immutable поведения. RAG:✅ 2026-09-10 — официальные материалы
   Казначейства и руководство ЕИС перечислены в `PARSING_PRODUCTION_ACCEPTANCE.md:323-355`.
8. Laravel 13 filesystem write по умолчанию возвращает `false` при неудаче, а при
   disk option `'throw' => true` бросает `League\Flysystem\UnableToWriteFile`; P3.2 обязан
   обработать обе формы как failed write. RAG:✅ 2026-09-10 — Laravel 13.x File Storage,
   «Failed Writes»: <https://laravel.com/docs/13.x/filesystem#failed-writes>. Confidence: medium
   (один official primary source; Context7 `/laravel/docs/__branch__13.x` не вернул секцию,
   сверка выполнена perplexity-web с official-source constraint).

## Pre-check Task

- `plan-lint.py plans/archive/2026.08.06-CRAWL-HARDEN` — OK.
- `plan-views.py state --all --check` — 66 views fresh.
- `plan-views.py decisions --check` — stale `_current.md` относительно текущего runtime.
- `plan-views.py status --check` — stale `status.md` относительно текущего runtime.

Архив не регенерируется: он исторический read-only input, а не target этой design-сессии.
