<!-- plan-projection
projection: decision-archive
projection_version: v1
source_scope: decisions/
through: 2026-08-09/D9
inputs_sha256: 5176bb26c968c8aa62ea68099da13edeeeb61aeedcc491cbdc7e222c2e92015c
generated_by: task plan-views decisions
-->

# Архив решений

> Не править руками: это генерируемый вид.

| ID | Date | Status | Items | Решение |
|:--|:--|:--|:--|:--|
| D11 | 2026-08-09 | superseded | P0.6 | Supersedes D2 только в арифметике skeleton: точный объём — 13 фаз / 74 items. Layout v2, contract-first, Design Passes 1/3 и запрет исполнения до полной детализации остаются без изменений. RAG:— (repo-grounded: status.md). |
| D13 | 2026-08-09 | superseded | P0.1, P0.2, P0.3, P0.4, P0.5, P0.6 | P0 остаётся design-only фазой без product writes. P0.1 и P0.2 идут последовательно; P0.3–P0.5 исполняются plan-owned research-fanout workflow; P0.6 выполняет frontier synthesis, после чего отдельный read-only workflow аудирует contracts до GREEN или двух fix rounds. RAG:— (repo-grounded: `research/01-P0-execution-design.md` и plan-protocol §7). |
| D2 | 2026-08-09 | superseded | P0.6 | План создаётся сразу в Layout v2, contract-first, с Design Passes 1/3 и запретом исполнения до детализации всех фаз и finish/audit-design. RAG:— (repo-grounded: AGENTS.md и plan.md). |
| D3 | 2026-08-09 | superseded | — | ACTIVE переключается на новый план без Paused By у 2026.08.06-CRAWL-HARDEN. Физическая архивация прежнего плана остаётся отдельной командой plan-close archive. RAG:— (repo-grounded: brief/01-refinements.md). |
| D46 | 2026-08-09 | superseded | finish | Supersedes D11 только в design-budget: владелец явно сокращает `Design Passes` с 1/3 до 1/1 и разрешает выполнить один полный cross-phase `finish`. Точный объём D11 — 13 фаз / 74 items — сохраняется. Исполнение по-прежнему запрещено до чистого `finish` и отдельного fresh-context `plan-audit design` на frontier/xhigh. RAG:— (repo-grounded: `brief/01-refinements.md`, owner decision 2026-08-09). |
| D47 | 2026-08-09 | superseded | finish | Design pass 1/1 завершает cross-phase authoring reconcile для Layout v2: 13 фаз и 74 items сохраняются без scope change. Исправлены четыре класса связности: materialized P0.6 output carriers; frozen `root/contracts/release-readiness-v1.md` добавлен в contracts P1–P12 и Required Reads их entry items; phase-index P0/P10–P12 выровнены по external-fact/DoR schema; handoffs, intent trace, index и roadmap переведены на единый post-finish state. RAG:— (repo-grounded: полный plan tree и Task checks 2026-08-09). |
