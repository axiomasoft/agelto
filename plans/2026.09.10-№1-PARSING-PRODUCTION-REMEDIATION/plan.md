# 2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION — План №1 — Ремедиация production-парсинга ЕИС

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | 2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION |
| Title | План №1 — Ремедиация production-парсинга ЕИС |
| Layout | v2 |
| Document Type | Executable Master Plan |
| Authoring Model | frontier/high |
| Repository | agelto |
| Related Packages | Laravel 13 · PostgreSQL · Redis/Horizon · object storage |
| Execution Mode | phase-first |
| Target Operator Classes | implementation (exec) · frontier (design/audit/live gates) |
| Approval Owner | Dmitry Vostrikov |
| Home | repo:agelto |
| Design Passes | 2/2 — 5 фаз / 17 items; pass 1 дал полную file-level детализацию по явному требованию владельца; pass 2 (`finish`, 2026-09-10) сверил цепочку зависимостей, D2–D4/D6→D7–D10, Required Reads, routing и audit-ready roadmap; bounded repairs по design audits #1–#2 не расширили design budget и закрыли только возвращённые carrier defects; candidate ожидает independent design audit |
| supersedes | —; терминальный `2026.08.06-CRAWL-HARDEN` остаётся provenance, не переоткрывается |

## 1. Context

Аудит 2026-09-10 подтвердил пригодность PostgreSQL/Horizon архитектуры, но запретил автономный
production из-за конечного набора defects полноты, конкурентности, snapshot truth, storage truth,
recovery и timeout semantics. Независимая приёмка воспроизвела исходные A1–A9/B1 и добавила
NEW-1/NEW-2. B2 остаётся неизвестным свойством ЕИС, а B3 — неблокирующей observability-дырой.

План исправляет существующие contracts без нового workflow engine и без ослабления guards.
Порядок жёсткий: сначала партиции, затем listing/recovery ownership, затем trusted snapshots,
после этого storage/retry/continuations/time budgets и только в конце live A1/B2 verification,
независимая приёмка и controlled-production. Полная трассировка —
`research/00-user-intent.md`; технический синтез — `research/01-remediation-architecture.md`.

## 2. Execution Rules

- Исполнять phase-first; item стартует только после terminal prerequisites и fresh bundle/state.
- Перед каждым тестовым прогоном выполнять `make test-preflight`; destructive DB-команды — только
  с `APP_ENV=testing`, `DB_DATABASE=agelto_test`, `DB_HOST=postgres`.
- Тесты — `test('<русский intent>', fn)`, данные через factories; stray HTTP запрещён.
- Реальные запросы к ЕИС допустимы только в P4.1/P4.3 после owner gate; обычные tests их не делают.
- Controllers остаются HTTP-only; короткая mutation transaction — Action; repositories — persistence;
  crawling/parsing/AI — идемпотентные Horizon jobs.
- `SourceTlsTrust` — единственный TLS trust holder; `verify => false` запрещён; proxy — только
  через `ProxyResolver` и `ProxyScope`.
- Нельзя менять assertions, isolation guards, security checks или production defaults ради зелени.
- Dirty worktree: не stage/reset/перезаписывать unrelated hunks; перед каждым item сверять Files.
- Regression/fault-injection обязан падать на старом поведении или иметь deterministic rollback
  probe; зелёный happy-path сам по себе finding не закрывает.
- При signal: observe → minimal reproduce → classify → act → verify; local reversible repair — в
  том же attempt, safety/authority/acceptance — только с actual-impact evidence.
- После item: journal → state/status/decision/bundle views → downstream/validation → handoff.
- Controlled production не авторизуется этим планом: P4.3 требует отдельного owner gate.

## 3. Routing

| Batch | Items | Model class/effort | Exec | Review | Почему |
|:--|:--|:--|:--|:--|:--|
| solo | P0.1 | implementation/high | manual | full | recommended: Blocker-миграция и проверка existing/fresh schema; data-integrity boundary не объединяется с application slices |
| B-P1-CRAWL | P1.1 | implementation/high | manual | full | recommended: Общие `FreshRunProcessor`/cursor fixtures и multi-page validation переиспользуются P1.2 |
| B-P1-CRAWL | P1.2 | implementation/high | manual | full | recommended: Producer→consumer с P1.1: stop semantics и hard-cap меняют один run contract |
| B-P1-RECOVERY | P1.3 | implementation/high | manual | full | recommended: Общий run/cursor/PostgreSQL harness с P1.4; scope/checkpoint предшествует lease |
| B-P1-RECOVERY | P1.4 | implementation/high | manual | full | recommended: Два process/session probe переиспользует setup P1.3; execution ownership завершает фазу |
| B-P2-SNAPSHOT | P2.1 | implementation/high | manual | full | recommended: Typed snapshot outcome и fixtures становятся входом reconcile P2.2 |
| B-P2-SNAPSHOT | P2.2 | implementation/high | manual | full | recommended: Тот же detail transaction/fixtures; B1 нельзя чинить до complete-snapshot P2.1 |
| solo | P2.3 | frontier/high | manual | full | recommended: B2 — unknown source contract и owner/live boundary; не смешивается с deterministic code batch |
| B-P3-STORAGE | P3.1 | implementation/high | manual | full | recommended: Due download route и storage fault fixtures переиспользуются P3.2 |
| B-P3-STORAGE | P3.2 | implementation/high | manual | full | recommended: Source/archive object truth меняет общий document status/version contract |
| B-P3-STORAGE | P3.3 | implementation/high | manual | full | recommended: Тот же disk failure matrix переносится на representations после source truth |
| solo | P3.4 | implementation/high | manual | full | recommended: Cross-stage recovery/dual-write windows требуют отдельного state-machine review |
| solo | P3.5 | implementation/high | manual | full | recommended: Конфигурационный lease/timeout boundary и real-worker proof отделены от state repair |
| solo | P4.1 | frontier/high | manual | full | required: Live ЕИС A1/B2 traffic и условная B2-remediation требуют owner-authorized external evidence до финальной приёмки |
| solo | P4.2 | frontier/xhigh | manual | full | required: Независимый offline adversarial verdict по 12 blockers и A1/B2 machine evidence не совмещается с implementation/live author или product repair |
| solo | P4.3 | frontier/high | manual | full | required: owner overrides 2026-09-11 сняли xhigh-floor и по D12 закрыли недоступный production scope как terminal local-only deviation |
| solo | P4.4 | implementation/medium | plan-exec | full | recommended: Неблокирующий локальный observability slice идёт после launch decision и не меняет gate verdict |

## 5. Decision Log

Каталог решений: `decisions/`; собрать/проверить:
`python3 <task-marketplace>/scripts/plan-views.py decisions --plan-dir plans/2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION [--check]`.
